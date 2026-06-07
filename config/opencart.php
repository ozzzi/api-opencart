<?php

declare(strict_types=1);

return [
    /*
     * Base URL of the OpenCart store. Used to build product URLs from url_alias keywords.
     * Example: https://shop.example.com
     */
    'store_url' => env('OC_STORE_URL', ''),

    /*
     * Maps language codes used by the chat system to OpenCart language_id values.
     * These IDs vary per installation — verify in the `oc_language` table.
     */
    'language_map' => [
        'uk' => (int) env('OC_LANGUAGE_ID_UK', 3),
        'ru' => (int) env('OC_LANGUAGE_ID_RU', 1),
    ],
];
