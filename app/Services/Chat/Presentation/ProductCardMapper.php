<?php

declare(strict_types=1);

namespace App\Services\Chat\Presentation;

use App\Services\Chat\Catalog\Contracts\PriceFormatterInterface;
use App\Services\Chat\Catalog\ProductImageUrlBuilder;
use App\Services\Chat\DTO\Blocks\ComparisonProduct;
use App\Services\Chat\DTO\Blocks\ComparisonRow;
use App\Services\Chat\DTO\Blocks\ProductAttribute;
use App\Services\Chat\DTO\Blocks\ProductCard;
use App\Services\Chat\DTO\Blocks\ProductComparisonBlock;
use App\Services\Chat\DTO\Blocks\ProductPrice;
use App\Services\Chat\DTO\Blocks\ProductsBlock;

/**
 * Turns live catalog rows into presentational blocks.
 *
 * The single place where OpenCart data becomes something the widget renders, shared
 * by show_products and compare_products so both speak the same card vocabulary
 * (task-structured-output.md §2.4).
 */
final class ProductCardMapper
{
    public function __construct(
        private readonly PriceFormatterInterface $priceFormatter,
        private readonly ProductImageUrlBuilder $imageUrlBuilder,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $products output of OpenCartCatalogInterface::getProductDetails()
     */
    public function toProductsBlock(array $products): ProductsBlock
    {
        return new ProductsBlock(array_map($this->toCard(...), $products));
    }

    /** @param array<string, mixed> $details */
    public function toCard(array $details): ProductCard
    {
        return new ProductCard(
            id: (int) $details['product_id'],
            name: (string) $details['name'],
            url: (string) $details['url'],
            image: $this->imageUrlBuilder->build($details['image'] ?? null),
            price: $this->toPrice($details),
            inStock: (bool) ($details['in_stock'] ?? false),
            availability: (string) ($details['availability'] ?? ''),
            attributes: $this->toCardAttributes($details),
        );
    }

    /**
     * Rows are ordered price → characteristics → availability, matching the block
     * schema. Out-of-stock products are deliberately kept: their absence from stock
     * is part of what the customer is comparing.
     *
     * @param list<array<string, mixed>> $products
     */
    public function toComparisonBlock(array $products): ProductComparisonBlock
    {
        $columns = array_map(
            fn (array $details): ComparisonProduct => new ComparisonProduct(
                id: (int) $details['product_id'],
                name: (string) $details['name'],
                url: (string) $details['url'],
                image: $this->imageUrlBuilder->build($details['image'] ?? null),
            ),
            $products,
        );

        $rows = [
            new ComparisonRow(
                label: (string) config('opencart.comparison_labels.price'),
                values: array_map(
                    fn (array $details): string => $this->toPrice($details)->current,
                    $products,
                ),
            ),
            ...$this->buildAttributeRows($products),
            new ComparisonRow(
                label: (string) config('opencart.comparison_labels.availability'),
                values: array_map(
                    static fn (array $details): string => (string) ($details['availability'] ?? ''),
                    $products,
                ),
            ),
        ];

        return new ProductComparisonBlock($columns, $rows);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * An active special becomes the current price and pushes the regular price into
     * `old`, which the widget renders struck through.
     *
     * @param array<string, mixed> $details
     */
    private function toPrice(array $details): ProductPrice
    {
        $regular = (float) $details['price'];
        $special = $details['special_price'] ?? null;

        if ($special === null) {
            return new ProductPrice(
                current: $this->priceFormatter->format($regular),
                currentRaw: $regular,
                old: null,
                oldRaw: null,
                currency: $this->priceFormatter->currencyCode(),
            );
        }

        return new ProductPrice(
            current: $this->priceFormatter->format((float) $special),
            currentRaw: (float) $special,
            old: $this->priceFormatter->format($regular),
            oldRaw: $regular,
            currency: $this->priceFormatter->currencyCode(),
        );
    }

    /**
     * A card shows only the first few characteristics — it is a teaser, not a spec
     * sheet; the full list lives on the product page.
     *
     * @param  array<string, mixed> $details
     * @return list<ProductAttribute>
     */
    private function toCardAttributes(array $details): array
    {
        $attributes = [];

        foreach ((array) ($details['attributes'] ?? []) as $attribute) {
            $label = mb_trim((string) ($attribute['name'] ?? ''));
            $value = mb_trim((string) ($attribute['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $attributes[] = new ProductAttribute($label, $value);
        }

        return array_slice($attributes, 0, (int) config('opencart.card_attributes_limit', 4));
    }

    /**
     * One row per characteristic found on any product, in first-encounter order,
     * with values aligned to the product order and null where a product lacks it.
     *
     * @param  list<array<string, mixed>> $products
     * @return list<ComparisonRow>
     */
    private function buildAttributeRows(array $products): array
    {
        /** @var list<string> $labels */
        $labels = [];

        foreach ($products as $details) {
            foreach ((array) ($details['attributes'] ?? []) as $attribute) {
                $label = mb_trim((string) ($attribute['name'] ?? ''));

                if ($label !== '' && ! in_array($label, $labels, strict: true)) {
                    $labels[] = $label;
                }
            }
        }

        return array_map(
            static fn (string $label): ComparisonRow => new ComparisonRow(
                label: $label,
                values: array_map(
                    static function (array $details) use ($label): ?string {
                        foreach ((array) ($details['attributes'] ?? []) as $attribute) {
                            if (mb_trim((string) ($attribute['name'] ?? '')) === $label) {
                                return (string) $attribute['value'];
                            }
                        }

                        return null;
                    },
                    $products,
                ),
            ),
            $labels,
        );
    }
}
