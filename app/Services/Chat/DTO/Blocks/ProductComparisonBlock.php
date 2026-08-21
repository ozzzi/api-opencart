<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/**
 * A side-by-side comparison table (task-structured-output.md §3.2.2).
 *
 * Unlike a products block this one keeps out-of-stock products: "not available" is
 * itself a comparison result the customer needs to see.
 */
final readonly class ProductComparisonBlock implements BlockInterface
{
    /**
     * @param list<ComparisonProduct> $products column order
     * @param list<ComparisonRow>     $rows     each aligned to $products
     */
    public function __construct(
        public array $products,
        public array $rows,
    ) {
    }

    public function type(): string
    {
        return 'product_comparison';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'products' => array_map(
                static fn (ComparisonProduct $product): array => $product->toArray(),
                $this->products,
            ),
            'rows' => array_map(
                static fn (ComparisonRow $row): array => $row->toArray(),
                $this->rows,
            ),
        ];
    }
}
