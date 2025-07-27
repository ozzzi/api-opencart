<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\Opensearch;

use App\Services\Search\Contracts\Searcher;
use App\Services\Search\DTO\Facet;
use App\Services\Search\DTO\Hit;
use App\Services\Search\DTO\Result;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Exceptions\SearchException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use OpenSearch\Client;
use stdClass;
use Throwable;

use function mb_strtolower;

final class OpensearchSearcher implements Searcher
{
    public function __construct(
        private Client $client,
        private Schema $schema
    ) {
    }

    public function search(
        string $indexName,
        string $query = '',
        array $filters = [],
        array $sorts = [],
        int $limit = 10,
        int $offset = 0,
        array $options = []
    ): Result {
        $this->getSchemaConfig($indexName);

        $searchParams = [
            'index' => $indexName,
            '_source' => ['product_id', 'name_ru', 'name_ua', 'price'],
            'search_pipeline' => 'hybrid-search-pipeline',
            'body' => [
                'suggest' => [
                    'autocomplete' => [
                        'prefix' => $query,
                        'completion' => [
                            'field' => 'name_suggest',
                            'size' => 5,
                            'fuzzy' => [
                                'fuzziness' => 1,
                            ],
                        ]
                    ],
                    'spellcheck_ru' => [
                        'text' => $query,
                        'term' => [
                            'field' => 'name_ru',
                        ],
                    ],
                    'spellcheck_ua' => [
                        'text' => $query,
                        'term' => [
                            'field' => 'name_ua',
                        ],
                    ],
                ],
                'query' => [
                    'hybrid' => [
                        'queries' => [
                            [
                                'bool' => [
                                    'should' => $this->getShould($query),
                                ],
                            ],
                            [
                                'neural' => [
                                    'name_vector' => [
                                        'query_text' => $query,
                                        'model_id' => config('services.search.model_id'),
                                        'min_score' => config('services.search.distance_threshold'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'categories' => [
                        'terms' => [
                            'field' => 'category_id',
                            'size' => 5,
                        ],
                    ],
                ],
                'highlight' => [
                    'fields' => [
                        'name_ru' => new stdClass(),
                        'name_ua' => new stdClass(),
                    ]
                ]
            ],
            'explain' => config('services.search.debug'),
        ];

        if (count($sorts)) {
            $searchParams['body']['sort'] = $this->buildSorts($sorts);
        }

        $searchParams['size'] = $limit;

        if ($offset !== 0) {
            $searchParams['from'] = $offset;
        }

        try {
            $results = $this->client->search($searchParams);
        } catch (Throwable $e) {
            throw  new SearchException($e->getMessage(), $e->getCode(), $e);
        }

        return new Result(
            hits: $this->getHits($results['hits']['hits']),
            total: (int) $results['hits']['total']['value'],
            facets: $this->getFacets($results['aggregations'] ?? []),
            suggest: $this->getSuggest($results['suggest']['autocomplete']),
            didYouMean: $this->getDidYouMean($results['suggest']),
        );
    }

    /**
     * @param string $query
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function getShould(string $query): array
    {
        return [
            [
                'match' => [
                    'sku' => [
                        'query' => $query,
                        'fuzziness' => 1,
                        '_name' => 'sku_match',
                    ],
                ],
            ],
            [
                'match' => [
                    'model' => [
                        'query' => $query,
                        'fuzziness' => 1,
                        '_name' => 'model_match',
                    ],
                ],
            ],
            [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['name_ru', 'name_ua'],
                    'type' => 'best_fields',
                    'fuzziness' => 'AUTO',
                    '_name' => 'name_match',
                ]
            ],
            [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['description_ru', 'description_ua'],
                    'type' => 'best_fields',
                    'fuzziness' => 1,
                    'minimum_should_match' => 2,
                    '_name' => 'description_match',
                ]
            ],
        ];
    }

    /**
     * @throws IndexNotFoundException
     */
    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }

    /**
     * @param array<string, string> $sorts
     * @return array<int, array<string, mixed>>
     */
    private function buildSorts(array $sorts): array
    {
        $formattedSorts = [];

        foreach ($sorts as $attribute => $direction) {
            $direction = mb_strtolower($direction);
            $formattedSorts[][$attribute] = [
                'order' => $direction,
            ];
        }

        return $formattedSorts;

    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, Hit>
     */
    private function getHits(array $results): array
    {
        $hits = [];

        foreach ($results as $result) {
            $hits[] = new Hit(
                id: $result['_id'],
                document: $result['_source'],
                score: (float) $result['_score'],
                highlight: $this->getHighlight($result['highlight'] ?? []),
            );
        }

        return $hits;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, List<Facet>>
     */
    private function getFacets(array $results): array
    {
        $facets = [];

        foreach ($results as $key => $result) {
            foreach ($result['buckets'] as $item) {
                $facets[$key][] = new Facet(
                    count: $item['doc_count'],
                    value: $item['key'],
                );
            }
        }

        return $facets;
    }

    /**
     * @param array<string, array<int, mixed>> $result
     * @return string
     */
    private function getHighlight(array $result): string
    {
        foreach (['name_ua', 'name_ru'] as $field) {
            if (isset($result[$field])) {
                return $result[$field][0];
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return string[]
     */
    private function getSuggest(array $results): array
    {
        $suggestions = [];

        if (!isset($results[0])) {
            return $suggestions;
        }

        foreach ($results[0]['options'] as $option) {
            $suggestions[] = $option['text'];
        }

        return $suggestions;
    }

    /**
     * @param array<string, array<int, mixed>> $results
     * @return array<string, string>
     */
    private function getDidYouMean(array $results): array
    {
        $spellChecks = [];

        foreach (['spellcheck_ru', 'spellcheck_ua'] as $field) {
            if (!isset($results[$field])) {
                continue;
            }

            $spellChecks[$field] = $results[$field][0]['options'][0]['text'] ?? '';
        }

        return $spellChecks;
    }
}
