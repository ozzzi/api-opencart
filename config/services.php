<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'search' => [
        'key' => env('SEARCH_API_KEY', ''),
        'user' => env('SEARCH_USER', ''),
        'host' => env('SEARCH_HOST', ''),
        'port' => env('SEARCH_PORT', ''),
        'ssl' => env('SEARCH_SSL', false),
        'model' => env('SEARCH_EMBEDDING_MODEL', ''),
        'model_id' => env('OPENSEARCH_MODEL_ID', ''),
        'embedder_url' => env('EMBEDDED_URL', ''),
        'debug' => env('SEARCH_DEBUG', false),
        'distance_threshold' => env('SEARCH_DISTANCE_THRESHOLD', 0.2),
    ],
];
