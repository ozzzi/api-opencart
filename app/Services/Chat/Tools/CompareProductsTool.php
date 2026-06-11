<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

final class CompareProductsTool implements ToolInterface
{
    public function __construct(
        private readonly OpenCartCatalogInterface $catalog,
    ) {
    }

    public function getName(): string
    {
        return 'compare_products';
    }

    public function getDescription(): string
    {
        return 'Compare 2–4 products side by side: live price, availability, and all attributes. '
            .'Returns a structured comparison table so you can explain differences and recommend '
            .'the best option for the customer\'s needs.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'product_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer', 'minimum' => 1],
                    'minItems'    => 2,
                    'maxItems'    => 4,
                    'description' => 'List of 2–4 product IDs to compare.',
                ],
                'lang' => [
                    'type'        => 'string',
                    'enum'        => ['ru', 'uk'],
                    'description' => 'Language for product names and attributes. Defaults to session language.',
                ],
            ],
            'required' => ['product_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        /** @var list<int> $productIds */
        $productIds = array_map('intval', (array) $arguments['product_ids']);
        $lang = isset($arguments['lang']) ? (string) $arguments['lang'] : ($session->language ?? 'ru');

        $products = [];
        $notFound = [];

        foreach ($productIds as $productId) {
            $details = $this->catalog->getProductDetails($productId, $lang);

            if ($details === null) {
                $notFound[] = $productId;
            } else {
                $products[] = $details;
            }
        }

        if ($products === []) {
            return json_encode(
                ['found' => false, 'not_found_ids' => $notFound],
                JSON_UNESCAPED_UNICODE,
            );
        }

        return json_encode(
            [
                'found'        => true,
                'products'     => $this->buildProductSummaries($products),
                'comparison'   => $this->buildAttributeComparison($products),
                'not_found_ids' => $notFound,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a concise summary for each product (identity + pricing + stock).
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function buildProductSummaries(array $products): array
    {
        return array_map(static function (array $p): array {
            return [
                'product_id'    => $p['product_id'],
                'name'          => $p['name'],
                'price'         => $p['price'],
                'special_price' => $p['special_price'],
                'in_stock'      => $p['in_stock'],
                'url'           => $p['url'],
            ];
        }, $products);
    }

    /**
     * Build a cross-product attribute table: attribute name → [product_id => value].
     *
     * All attribute names found across any product are included; missing values
     * are represented as null so the LLM can highlight gaps.
     *
     * @param  list<array<string, mixed>>  $products
     * @return array<string, array<int, string|null>>
     */
    private function buildAttributeComparison(array $products): array
    {
        // Collect all attribute names in encounter order
        $allAttributes = [];

        foreach ($products as $product) {
            foreach ((array) ($product['attributes'] ?? []) as $attr) {
                $name = (string) ($attr['name'] ?? '');

                if ($name !== '' && ! in_array($name, $allAttributes, strict: true)) {
                    $allAttributes[] = $name;
                }
            }
        }

        $table = [];

        foreach ($allAttributes as $attrName) {
            $row = [];

            foreach ($products as $product) {
                $productId = (int) $product['product_id'];
                $value = null;

                foreach ((array) ($product['attributes'] ?? []) as $attr) {
                    if (($attr['name'] ?? '') === $attrName) {
                        $value = (string) $attr['value'];
                        break;
                    }
                }

                $row[$productId] = $value;
            }

            $table[$attrName] = $row;
        }

        return $table;
    }
}
