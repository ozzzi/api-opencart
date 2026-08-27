<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use OpenSearch\Client;
use Throwable;

/**
 * Executes hybrid BM25 + k-NN search against a single OpenSearch index.
 *
 * Strategy:
 *   1. On the first call, check whether the normalization-processor search
 *      pipeline exists.  If it does, run a native `hybrid` query (single
 *      round-trip, server-side score normalisation).
 *   2. If the pipeline is absent or the feature is unsupported, fall back to
 *      separate BM25 and k-NN queries merged with Reciprocal Rank Fusion (RRF)
 *      in the application layer.
 *
 * The pipeline availability result is cached for the lifetime of the object
 * (i.e. per-request when registered as a singleton).
 */
final class HybridSearcher
{
    /**
     * Text fields queried by BM25.  Covers both kb_index and products_index;
     * OpenSearch silently skips unknown fields, so one list works for all.
     *
     * Public because CatalogBreadthProbe measures how many products a query
     * matches and must weigh the same fields the real search does — a probe
     * scoped differently would report a breadth the search never sees.
     *
     * Each field is queried together with its `.ru` / `.uk` hunspell sub-fields
     * (see IndexSchemas::multiLangTextField) — the root field uses the standard
     * analyzer and therefore matches word forms literally, which alone would
     * make "паракорд" miss a document containing only "паракорда".
     */
    public const array TEXT_FIELDS = [
        ...self::NAME_FIELDS,
        ...self::CATEGORY_FIELDS,
        ...self::ATTRIBUTE_FIELDS,
        ...self::BODY_FIELDS,
    ];

    private const array NAME_FIELDS = [
        'title^3', 'title.ru^3', 'title.uk^3',
        'name^3', 'name.ru^3', 'name.uk^3',
    ];
    private const array CATEGORY_FIELDS = [
        'category^2', 'category.ru^2', 'category.uk^2',
    ];
    private const array ATTRIBUTE_FIELDS = [
        'attributes', 'attributes.ru', 'attributes.uk',
    ];
    private const array BODY_FIELDS = [
        'content', 'content.ru', 'content.uk',
        'description', 'description.ru', 'description.uk',
    ];

    /** RRF constant — higher value reduces the impact of rank position. */
    private const int RRF_K = 60;
    /** @var bool|null null = not yet checked */
    private ?bool $pipelineAvailable = null;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Search an index using hybrid BM25 + k-NN scoring.
     *
     * @param  list<float>                       $queryVector  Pre-computed embedding.
     * @param  list<array<string, mixed>>         $filters      OpenSearch filter clauses.
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    public function search(
        string $index,
        string $queryText,
        array $queryVector,
        array $filters = [],
        int $topK = 5,
    ): array {
        if ($this->isPipelineAvailable()) {
            return $this->searchWithPipeline($index, $queryText, $queryVector, $filters, $topK);
        }

        return $this->searchWithRrf($index, $queryText, $queryVector, $filters, $topK);
    }

    /**
     * Whether the scores returned by search() are normalized to the 0…1 range.
     *
     * True when the OpenSearch normalization-processor pipeline is used; false
     * for the app-side RRF fallback, whose scores top out around 1/(RRF_K + 1)
     * and must never be compared against a normalized threshold.
     */
    public function usesNormalizedScores(): bool
    {
        return $this->isPipelineAvailable();
    }

    /**
     * @param  list<float>                $queryVector
     * @param  list<array<string, mixed>> $filters
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    private function searchWithPipeline(
        string $index,
        string $queryText,
        array $queryVector,
        array $filters,
        int $topK,
    ): array {
        $pipelineId = (string) config('opensearch.hybrid.pipeline_id');

        $params = [
            'index'           => $index,
            'search_pipeline' => $pipelineId,
            'body'            => [
                'size'  => $topK,
                'query' => [
                    'hybrid' => [
                        'queries' => [
                            $this->buildBm25Query($queryText, $filters),
                            $this->buildKnnQuery($queryVector, $topK, $filters),
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->client->search($params);

        return $this->extractHits($response);
    }

    /**
     * @param  list<float>                $queryVector
     * @param  list<array<string, mixed>> $filters
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    private function searchWithRrf(
        string $index,
        string $queryText,
        array $queryVector,
        array $filters,
        int $topK,
    ): array {
        $fetchSize = $topK * 3;

        $bm25Hits = $this->extractHits($this->client->search([
            'index' => $index,
            'body'  => ['size' => $fetchSize, 'query' => $this->buildBm25Query($queryText, $filters)],
        ]));

        $knnHits = $this->extractHits($this->client->search([
            'index' => $index,
            'body'  => ['size' => $fetchSize, 'query' => $this->buildKnnQuery($queryVector, $fetchSize, $filters)],
        ]));

        return $this->mergeWithRrf($bm25Hits, $knnHits, $topK);
    }

    /**
     * Weighted Reciprocal Rank Fusion.
     *
     * score(d) = Σ  weight_i / (RRF_K + rank_i(d))  for each result list i
     *
     * The weights are the same `opensearch.hybrid.*` values the
     * normalization-processor uses. Without them this fallback ranked BM25 and
     * k-NN 50/50 regardless of configuration, so the two modes disagreed: a
     * document winning k-NN could outrank the BM25 winner here but not there,
     * and re-tuning the weights had no effect on the fallback at all.
     *
     * @param  list<array{_id:string,_score:float,_source:array<string,mixed>}> $listA  BM25 hits.
     * @param  list<array{_id:string,_score:float,_source:array<string,mixed>}> $listB  k-NN hits.
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    private function mergeWithRrf(array $listA, array $listB, int $topK): array
    {
        /** @var array<string, array{score:float,source:array<string,mixed>}> $merged */
        $merged = [];

        $weights = [
            (float) config('opensearch.hybrid.bm25_weight', 0.5),
            (float) config('opensearch.hybrid.knn_weight', 0.5),
        ];

        foreach ([$listA, $listB] as $listIndex => $list) {
            foreach ($list as $rank => $hit) {
                $id = $hit['_id'];
                $rrfScore = $weights[$listIndex] / (self::RRF_K + $rank + 1);

                if (isset($merged[$id])) {
                    $merged[$id]['score'] += $rrfScore;
                } else {
                    $merged[$id] = ['score' => $rrfScore, 'source' => $hit['_source']];
                }
            }
        }

        uasort($merged, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $results = [];

        foreach (array_slice($merged, 0, $topK, preserve_keys: true) as $id => $data) {
            $results[] = ['_id' => $id, '_score' => $data['score'], '_source' => $data['source']];
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Query builders
    // -------------------------------------------------------------------------

    /**
     * Additive BM25 scoring: one `should` clause per semantic field group plus a
     * coverage bonus, so matching in two places beats matching in one.
     *
     * @param  list<array<string, mixed>> $filters
     * @return array<string, mixed>
     */
    private function buildBm25Query(string $queryText, array $filters): array
    {
        $should = [
            $this->groupClause($queryText, self::NAME_FIELDS, 8.0, type: 'phrase'),
            $this->groupClause($queryText, self::NAME_FIELDS, 3.0, fuzziness: 'AUTO'),
            $this->groupClause($queryText, self::CATEGORY_FIELDS, 2.0),
            $this->groupClause($queryText, self::ATTRIBUTE_FIELDS, 2.0),
            $this->groupClause($queryText, self::BODY_FIELDS, 1.5),
        ];

        $coverage = $this->buildCoverageClause($queryText);

        if ($coverage !== null) {
            $should[] = $coverage;
        }

        $bool = [
            'should'               => $should,
            'minimum_should_match' => 1,
        ];

        if ($filters !== []) {
            $bool['filter'] = $filters;
        }

        return ['bool' => $bool];
    }

    /**
     * One `should` clause over a single field group.
     *
     * `fuzziness` is not accepted alongside `type: phrase`, so the two are never
     * passed together.
     *
     * @param  list<string>        $fields
     * @return array<string, mixed>
     */
    private function groupClause(
        string $queryText,
        array $fields,
        float $boost,
        string $type = 'best_fields',
        ?string $fuzziness = null,
    ): array {
        $clause = [
            'query'  => $queryText,
            'fields' => $fields,
            'type'   => $type,
            'boost'  => $boost,
        ];

        if ($fuzziness !== null) {
            $clause['fuzziness'] = $fuzziness;
        }

        return ['multi_match' => $clause];
    }

    /**
     * Rewards documents where every significant term of the query is found
     * somewhere — the signal that separates "Темляк «Мумія»", whose description
     * mentions a skull, from the thirty other lanyards that match only "темляк".
     *
     * Deliberately a `should` and not a `must`: a query whose terms appear
     * nowhere together still returns its best partial matches instead of an
     * empty result set, so behaviour degrades to what it was rather than
     * breaking.
     *
     * Returns null for single-term queries, where it would only duplicate the
     * group clauses above.
     *
     * @return array<string, mixed>|null
     */
    private function buildCoverageClause(string $queryText): ?array
    {
        $terms = QueryTerms::significant($queryText);

        if (count($terms) < 2) {
            return null;
        }

        return [
            'bool' => [
                'must' => array_map(
                    fn (string $term): array => $this->groupClause($term, self::TEXT_FIELDS, 1.0),
                    $terms,
                ),
                'boost' => 6.0,
            ],
        ];
    }

    /**
     * @param  list<float>                $vector
     * @param  list<array<string, mixed>> $filters
     * @return array<string, mixed>
     */
    private function buildKnnQuery(array $vector, int $k, array $filters): array
    {
        $knnClause = ['vector' => $vector, 'k' => $k];

        if ($filters !== []) {
            $knnClause['filter'] = ['bool' => ['filter' => $filters]];
        }

        return ['knn' => ['content_vector' => $knnClause]];
    }

    /**
     * @param  array<string, mixed>                                              $response
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    private function extractHits(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];

        return array_values(array_map(
            fn (array $hit) => [
                '_id'     => (string) $hit['_id'],
                '_score'  => (float) ($hit['_score'] ?? 0.0),
                '_source' => (array) ($hit['_source'] ?? []),
            ],
            $hits,
        ));
    }

    private function isPipelineAvailable(): bool
    {
        if ($this->pipelineAvailable !== null) {
            return $this->pipelineAvailable;
        }

        try {
            $id = (string) config('opensearch.hybrid.pipeline_id');
            $this->client->searchPipeline()->get(['id' => $id]);
            $this->pipelineAvailable = true;
        } catch (Throwable) {
            $this->pipelineAvailable = false;
        }

        return $this->pipelineAvailable;
    }
}
