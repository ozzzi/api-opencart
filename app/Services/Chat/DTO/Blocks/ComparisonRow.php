<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/**
 * One row of a comparison table.
 *
 * `values` is aligned to the block's product order and always has the same length;
 * a product missing this characteristic contributes null, which the widget renders
 * as an em dash.
 */
final readonly class ComparisonRow
{
    /** @param list<string|null> $values */
    public function __construct(
        public string $label,
        public array $values,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'values' => $this->values,
        ];
    }
}
