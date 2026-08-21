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

    /*
     * Language used for the product cards and comparison blocks the assistant emits.
     * Kept separate from the session language: ShopAssistant always replies in
     * Ukrainian, so cards must match or the prose and the card disagree.
     */
    'card_language' => env('OC_CARD_LANGUAGE', 'uk'),

    /*
     * How absolute product image URLs are built from the relative path stored in
     * `product.image` (e.g. "catalog/demo/hp_1.jpg").
     *
     * 'original' — {store_url}/image/{path}. Always resolves, full-size payload.
     * 'cache'    — {store_url}/image/cache/{path}-{width}x{height}.{ext}. Lighter,
     *              but resolves only if OpenCart has already generated that exact
     *              size; we read the catalog over a read-only connection and cannot
     *              trigger its resizer. Switch once a size is known to be warmed.
     */
    'image' => [
        'strategy' => env('OC_IMAGE_STRATEGY', 'original'),
        'width' => (int) env('OC_IMAGE_WIDTH', 500),
        'height' => (int) env('OC_IMAGE_HEIGHT', 500),

        /* Filenames treated as "no image" — the widget draws its own placeholder. */
        'ignore' => ['no_image.png', 'placeholder.png'],
    ],

    /*
     * The `stock_status` table only holds the label shown when quantity <= 0
     * ("Немає в наявності", "Під замовлення"). OpenCart's storefront renders the
     * in-stock label from a language file, so it has to be configured here.
     */
    'availability' => [
        'in_stock' => env('OC_IN_STOCK_LABEL', 'В наявності'),
    ],

    /*
     * Used when the OpenCart `currency` / `setting` tables cannot be read, so card
     * prices degrade to a sane string instead of throwing.
     */
    'currency_fallback' => [
        'code' => env('OC_CURRENCY_CODE', 'UAH'),
        'symbol_left' => env('OC_CURRENCY_SYMBOL_LEFT', ''),
        'symbol_right' => env('OC_CURRENCY_SYMBOL_RIGHT', ' ₴'),
        'decimal_place' => (int) env('OC_CURRENCY_DECIMALS', 0),
        'value' => 1.0,
    ],

    /* Row labels for the product_comparison block. */
    'comparison_labels' => [
        'price' => 'Ціна',
        'availability' => 'Наявність',
    ],

    /* Maximum attribute rows rendered on a single product card. */
    'card_attributes_limit' => 4,
];
