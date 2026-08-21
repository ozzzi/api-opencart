<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/**
 * A row of 1–4 product cards (task-structured-output.md §3.2.1).
 *
 * Out-of-stock products never reach this block — they are filtered before it is
 * built (FR-4.4); `in_stock` is kept on the card for completeness.
 */
final readonly class ProductsBlock implements BlockInterface
{
    /** @param list<ProductCard> $items */
    public function __construct(
        public array $items,
    ) {
    }

    public function type(): string
    {
        return 'products';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'items' => array_map(
                static fn (ProductCard $card): array => $card->toArray(),
                $this->items,
            ),
        ];
    }
}
