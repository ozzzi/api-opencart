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

    /*
    |--------------------------------------------------------------------------
    | Product Clarification Gate
    |--------------------------------------------------------------------------
    |
    | Stops search_products from answering a query that does not discriminate the
    | catalog ("я хочу браслет").
    |
    | broad_hits_threshold is the only value that normally needs tuning; it and
    | `enabled` are mirrored in BotChatSettings so an operator can change them
    | from the admin panel. The rest stay here.
    |
    */

    'clarification' => [
        'enabled'              => true,
        'broad_hits_threshold' => 12,
        'max_query_terms'      => 2,
        'max_rounds'           => 1,
        'sample_size'          => 30,

        // Boundaries of the price range aggregation, ascending. Shop-specific:
        // these split the live catalog near its quartiles (p25 ≈ 675, p50 ≈ 1270,
        // p75 ≈ 1990). Buckets that leave 90% of hits in one range tell the
        // assistant nothing, so revisit them after a big catalog change.
        'price_buckets' => [8, 80, 150],

        // Words that carry no discriminating power and are ignored when counting
        // the significant terms of a query (UA/RU). Tokens shorter than 3 chars
        // are dropped regardless.
        'stop_words' => [
            'хочу', 'треба', 'потрібно', 'потрібен', 'потрібна', 'шукаю', 'шукав',
            'мені', 'мене', 'дайте', 'покажи', 'покажіть', 'підкажіть', 'порадьте',
            'купити', 'придбати', 'замовити', 'який', 'яка', 'яке', 'які', 'щось',
            'товар', 'товари', 'варіант', 'варіанти', 'наявність', 'будь', 'ласка',
            'нужен', 'нужна', 'нужно', 'ищу', 'мне', 'подскажите', 'посоветуйте',
            'покажите', 'купить', 'заказать', 'какой', 'какая', 'какие', 'что',
            'нибудь', 'товары', 'вариант', 'варианты', 'пожалуйста', 'есть',
        ],

        // Phrases that mean "stop asking, just show me what you have" (UA/RU).
        // Matched as substrings against the customer's latest message.
        'opt_out_phrases' => [
            'покажи що є', 'покажіть що є', 'давай що є', 'не важливо',
            'неважливо', 'байдуже', 'будь-який', 'будь який', 'все одно',
            'на твій розсуд', 'покажи все', 'покажи всі',
            'покажи что есть', 'покажите что есть', 'давай что есть',
            'не важно', 'неважно', 'любой', 'все равно', 'всё равно',
            'на твое усмотрение', 'покажи всё',
        ],
    ],

];
