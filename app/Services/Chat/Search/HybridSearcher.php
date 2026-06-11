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
    /** RRF constant — higher value reduces the impact of rank position. */
    private const int RRF_K = 60;

    /**
     * Text fields queried by BM25.  Covers both kb_index and products_index;
     * OpenSearch silently skips unknown fields, so one list works for all.
     */
    private const array TEXT_FIELDS = [
        'title', 'name', 'content', 'description', 'attributes', 'category',
    ];
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

    // -------------------------------------------------------------------------
    // Pipeline (native hybrid) path
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // App-side RRF fallback
    // -------------------------------------------------------------------------

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
     * Reciprocal Rank Fusion.
     *
     * score(d) = Σ  1 / (RRF_K + rank_i(d))  for each result list i
     *
     * @param  list<array{_id:string,_score:float,_source:array<string,mixed>}> $listA
     * @param  list<array{_id:string,_score:float,_source:array<string,mixed>}> $listB
     * @return list<array{_id:string,_score:float,_source:array<string,mixed>}>
     */
    private function mergeWithRrf(array $listA, array $listB, int $topK): array
    {
        /** @var array<string, array{score:float,source:array<string,mixed>}> $merged */
        $merged = [];

        foreach ([$listA, $listB] as $list) {
            foreach ($list as $rank => $hit) {
                $id = $hit['_id'];
                $rrfScore = 1.0 / (self::RRF_K + $rank + 1);

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
     * @param  list<array<string, mixed>> $filters
     * @return array<string, mixed>
     */
    private function buildBm25Query(string $queryText, array $filters): array
    {
        $multiMatch = [
            'multi_match' => [
                'query'  => $queryText,
                'fields' => self::TEXT_FIELDS,
                'type'   => 'best_fields',
            ],
        ];

        if ($filters === []) {
            return $multiMatch;
        }

        return [
            'bool' => [
                'must'   => [$multiMatch],
                'filter' => $filters,
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
