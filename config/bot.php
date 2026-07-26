<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Model Pricing Table
    |--------------------------------------------------------------------------
    |
    | Costs in USD per 1 000 tokens. Embedding models have no output cost.
    | These are defaults; bot_settings can supply per-model overrides at runtime.
    |
    */

    'model_prices' => [
        // Chat models
        'gpt-4o'              => ['input' => 0.0025,   'output' => 0.0100],
        'gpt-4o-mini'         => ['input' => 0.00015,  'output' => 0.0006],
        'gpt-4-turbo'         => ['input' => 0.0100,   'output' => 0.0300],
        'gpt-3.5-turbo'       => ['input' => 0.0005,   'output' => 0.0015],
        // Embedding models (output always 0)
        'text-embedding-3-small' => ['input' => 0.00002, 'output' => 0.0],
        'text-embedding-3-large' => ['input' => 0.00013, 'output' => 0.0],
        'text-embedding-ada-002' => ['input' => 0.00010, 'output' => 0.0],
    ],

    'embedding' => [
        'dimensions'  => (int) env('BOT_EMBEDDING_DIMENSIONS', 1536),
        'local_url'   => env('BOT_EMBEDDER_URL', ''),
        'local_token' => env('BOT_EMBEDDER_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Retrieval
    |--------------------------------------------------------------------------
    */

    /*
    | min_score is compared against the normalized hybrid score produced by the
    | OpenSearch normalization-processor (0…1, weights from config/opensearch.php).
    | On that scale a document matching only BM25 lands around 0.3 and one matching
    | only k-NN around 0.7, so anything above ~0.3 silently drops whole result sets.
    | Keep it low — topK is the real cut-off. It is not applied to the app-side RRF
    | fallback, whose scores live on an incomparable scale (max ≈ 1/61).
    */
    'retrieval' => [
        'kb_top_k'       => 5,
        'product_top_k'  => 4,
        'min_score'      => (float) env('BOT_RETRIEVAL_MIN_SCORE', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenSearch Index Names
    |--------------------------------------------------------------------------
    */

    'opensearch' => [
        'kb_index'       => 'chat_kb',
        'products_index' => 'chat_products',
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Context Window
    |--------------------------------------------------------------------------
    */

    'context' => [
        'window_size'       => 10,
        'summary_threshold' => 20,
        'max_tokens'        => 4096,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    */

    'circuit_breaker' => [
        'failure_threshold'      => 5,
        'recovery_timeout_sec'   => 60,
    ],

];
