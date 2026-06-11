<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

final class SearchProductsTool implements ToolInterface
{
    public function __construct(
        private readonly RetrievalServiceInterface $retrieval,
    ) {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Search the product catalog for items matching a user query. '
            .'Supports optional filters for price range, category, and stock availability. '
            .'Returns 2–4 matching products with name, price, availability, and a direct URL. '
            .'Always use this tool before recommending any product.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Product search query (e.g. "laptop for students", "wireless headphones").',
                ],
                'category' => [
                    'type'        => 'string',
                    'description' => 'Filter by product category name (e.g. "Ноутбуки", "Смартфоны").',
                ],
                'price_min' => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Minimum price (inclusive).',
                ],
                'price_max' => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Maximum price (inclusive).',
                ],
                'in_stock' => [
                    'type'        => 'boolean',
                    'description' => 'When true (default), only return products currently in stock.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 4,
                    'description' => 'Maximum number of products to return. Defaults to 4.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $query = (string) $arguments['query'];
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 4;

        $filters = ['lang' => $session->language ?? 'ru'];

        if (isset($arguments['category'])) {
            $filters['category'] = (string) $arguments['category'];
        }

        if (isset($arguments['price_min'])) {
            $filters['price_min'] = (float) $arguments['price_min'];
        }

        if (isset($arguments['price_max'])) {
            $filters['price_max'] = (float) $arguments['price_max'];
        }

        // in_stock defaults to true; only pass false when explicitly requested
        if (isset($arguments['in_stock']) && $arguments['in_stock'] === false) {
            $filters['in_stock'] = false;
        }

        $fragments = $this->retrieval->retrieveProducts($query, $filters, $limit);

        if ($fragments === []) {
            return json_encode(
                ['results' => [], 'found' => false],
                JSON_UNESCAPED_UNICODE,
            );
        }

        $results = array_map(static function ($fragment): array {
            $src = $fragment->metadata;

            return [
                'product_id' => $src['product_id'] ?? null,
                'name'       => $src['name'] ?? $fragment->content,
                'price'      => $src['price'] ?? null,
                'in_stock'   => $src['in_stock'] ?? true,
                'category'   => $src['category'] ?? null,
                'url'        => $src['url'] ?? null,
                'image'      => $src['image'] ?? null,
                'score'      => round($fragment->score, 4),
            ];
        }, $fragments);

        return json_encode(
            ['results' => $results, 'found' => true],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
