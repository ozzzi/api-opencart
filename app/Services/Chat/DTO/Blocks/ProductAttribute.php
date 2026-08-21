<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/** A single label/value characteristic rendered on a product card. */
final readonly class ProductAttribute
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
        ];
    }
}
