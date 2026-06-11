<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

/**
 * Canonical index definitions for the chat RAG layer.
 *
 * Each public method returns a body array ready to pass to the OpenSearch
 * "create index" endpoint.  Dimensions and k-NN parameters are taken from
 * config/opensearch.php so they stay consistent with the embedding client.
 *
 * Index doc-id conventions (for idempotent upsert):
 *   kb_index       → "{article_id}_{chunk_index}"
 *   products_index → "{product_id}_{lang}"
 */
final class IndexSchemas
{
    /**
     * Full create-index body for kb_index.
     *
     * Text fields carry both RU and UA hunspell analyzers so BM25 works
     * correctly regardless of which language a chunk is written in.
     *
     * @return array<string, mixed>
     */
    public static function kb(): array
    {
        $dim = self::dimensions();
        $knn = self::knnMethod();

        return [
            'settings' => [
                'index' => [
                    'knn' => true,
                    'knn.algo_param.ef_search' => config('opensearch.knn.ef_search', 128),
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
                'analysis' => self::analysisSettings(),
            ],
            'mappings' => [
                'properties' => [
                    'article_id'     => ['type' => 'integer'],
                    'chunk_index'    => ['type' => 'integer'],
                    'title'          => self::multiLangTextField(),
                    'content'        => self::multiLangTextField(),
                    'category'       => ['type' => 'keyword'],
                    'lang'           => ['type' => 'keyword'],
                    'is_active'      => ['type' => 'boolean'],
                    'content_vector' => [
                        'type'      => 'knn_vector',
                        'dimension' => $dim,
                        'method'    => $knn,
                    ],
                ],
            ],
        ];
    }

    /**
     * Full create-index body for products_index.
     *
     * @return array<string, mixed>
     */
    public static function products(): array
    {
        $dim = self::dimensions();
        $knn = self::knnMethod();

        return [
            'settings' => [
                'index' => [
                    'knn' => true,
                    'knn.algo_param.ef_search' => config('opensearch.knn.ef_search', 128),
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
                'analysis' => self::analysisSettings(),
            ],
            'mappings' => [
                'properties' => [
                    'product_id'     => ['type' => 'integer'],
                    'name'           => self::multiLangTextField(),
                    'description'    => self::multiLangTextField(),
                    'attributes'     => self::multiLangTextField(),
                    'category'       => [
                        'type'   => 'text',
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    'price'          => ['type' => 'float'],
                    'in_stock'       => ['type' => 'boolean'],
                    'url'            => ['type' => 'keyword'],
                    'image'          => ['type' => 'keyword'],
                    'lang'           => ['type' => 'keyword'],
                    'content_vector' => [
                        'type'      => 'knn_vector',
                        'dimension' => $dim,
                        'method'    => $knn,
                    ],
                ],
            ],
        ];
    }

    /**
     * Normalization-processor pipeline body for hybrid (BM25 + k-NN) search.
     *
     * Weights order: [BM25, k-NN] — matches the query sub-query order used
     * in HybridSearcher.
     *
     * @return array<string, mixed>
     */
    public static function hybridPipeline(): array
    {
        /** @var array{normalization:string,combination:string,bm25_weight:float,knn_weight:float} $cfg */
        $cfg = config('opensearch.hybrid');

        return [
            'description' => 'Hybrid BM25 + k-NN search pipeline',
            'phase_results_processors' => [
                [
                    'normalization-processor' => [
                        'normalization' => [
                            'technique' => $cfg['normalization'],
                        ],
                        'combination' => [
                            'technique'  => $cfg['combination'],
                            'parameters' => [
                                'weights' => [
                                    (float) $cfg['bm25_weight'],
                                    (float) $cfg['knn_weight'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Shared text field mapping with Russian and Ukrainian sub-fields.
     *
     * The root field uses the `standard` analyzer for broad matching;
     * `ru` and `uk` sub-fields carry language-specific hunspell stemming so
     * BM25 relevance improves for each locale.
     *
     * @return array<string, mixed>
     */
    private static function multiLangTextField(): array
    {
        return [
            'type'     => 'text',
            'analyzer' => 'standard',
            'fields'   => [
                'ru' => ['type' => 'text', 'analyzer' => 'russian_analyzer'],
                'uk' => ['type' => 'text', 'analyzer' => 'ukrainian_analyzer'],
            ],
        ];
    }

    /**
     * k-NN method block shared by both indexes.
     *
     * @return array<string, mixed>
     */
    private static function knnMethod(): array
    {
        /** @var array{engine:string,method:string,space_type:string,parameters:array<string,int>} $cfg */
        $cfg = config('opensearch.knn');

        return [
            'name'       => $cfg['method'],
            'engine'     => $cfg['engine'],
            'space_type' => $cfg['space_type'],
            'parameters' => $cfg['parameters'],
        ];
    }

    /**
     * Analysis settings with hunspell token filters for RU and UK.
     *
     * OpenSearch loads hunspell dictionaries from the node's hunspell directory
     * (mounted at /usr/share/opensearch/config/hunspell/ in docker).
     * The locale value must match the subdirectory name.
     *
     * @return array<string, mixed>
     */
    private static function analysisSettings(): array
    {
        return [
            'filter' => [
                'ru_hunspell' => [
                    'type'   => 'hunspell',
                    'locale' => 'ru_RU',
                    'dedup'  => true,
                ],
                'uk_hunspell' => [
                    'type'   => 'hunspell',
                    'locale' => 'uk_UA',
                    'dedup'  => true,
                ],
            ],
            'analyzer' => [
                'russian_analyzer' => [
                    'type'      => 'custom',
                    'tokenizer' => 'standard',
                    'filter'    => ['lowercase', 'ru_hunspell'],
                ],
                'ukrainian_analyzer' => [
                    'type'      => 'custom',
                    'tokenizer' => 'standard',
                    'filter'    => ['lowercase', 'uk_hunspell'],
                ],
            ],
        ];
    }

    private static function dimensions(): int
    {
        return (int) config('opensearch.embedding.dimensions', 384);
    }
}
