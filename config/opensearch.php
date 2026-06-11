<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    */

    'host'     => env('SEARCH_HOST', '127.0.0.1'),
    'port'     => (int) env('SEARCH_PORT', 9200),
    'user'     => env('SEARCH_USER', 'admin'),
    'password' => env('SEARCH_API_KEY', ''),
    'ssl'      => (bool) env('SEARCH_SSL', false),

    /*
    | Set to false only in local/dev to skip certificate verification.
    | Must be true in production.
    */
    'ssl_verification' => (bool) env('SEARCH_SSL_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Index Names
    |--------------------------------------------------------------------------
    */

    'indices' => [
        'kb'       => env('OPENSEARCH_KB_INDEX', 'chat_kb'),
        'products' => env('OPENSEARCH_PRODUCTS_INDEX', 'chat_products'),
    ],

    /*
    |--------------------------------------------------------------------------
    | k-NN (Approximate Nearest Neighbour)
    |--------------------------------------------------------------------------
    |
    | engine: nmslib | faiss | lucene
    | space_type: cosinesimil | l2 | innerproduct
    |
    */

    'knn' => [
        'engine'     => 'nmslib',
        'method'     => 'hnsw',
        'space_type' => 'cosinesimil',
        'parameters' => [
            'm'              => 16,
            'ef_construction' => 128,
        ],
        'ef_search' => 128,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search Pipeline
    |--------------------------------------------------------------------------
    |
    | Uses the OpenSearch normalization-processor pipeline when available.
    | Fallback: app-side Reciprocal Rank Fusion (RRF) in HybridSearcher.
    |
    | normalization: min_max | l2
    | combination:  arithmetic_mean | geometric_mean | harmonic_mean
    |
    */

    'hybrid' => [
        'pipeline_id'   => 'chat_hybrid_pipeline',
        'bm25_weight'   => 0.3,
        'knn_weight'    => 0.7,
        'normalization' => 'min_max',
        'combination'   => 'arithmetic_mean',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding
    |--------------------------------------------------------------------------
    |
    | dimensions must match the model output size.
    | Local model paraphrase-multilingual-MiniLM-L12-v2 → 384.
    | OpenAI text-embedding-3-small → 1536.
    |
    */

    'embedding' => [
        'dimensions' => (int) env('SEARCH_EMBEDDING_DIMENSIONS', 384),
        'model'      => env('SEARCH_EMBEDDING_MODEL', 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Misc
    |--------------------------------------------------------------------------
    */

    'distance_threshold' => (float) env('SEARCH_DISTANCE_THRESHOLD', 0.3),
    'debug'              => (bool) env('SEARCH_DEBUG', false),

];
