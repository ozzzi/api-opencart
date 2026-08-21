<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/**
 * Display-ready price for a product card.
 *
 * Both a formatted string and the raw number are sent: the widget renders the
 * string as-is (no currency logic on the client) and keeps the number for sorting
 * or client-side logic (task-structured-output.md §2.4).
 */
final readonly class ProductPrice
{
    public function __construct(
        public string $current,
        public float $currentRaw,
        public ?string $old,
        public ?float $oldRaw,
        public string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'current_raw' => $this->currentRaw,
            'old' => $this->old,
            'old_raw' => $this->oldRaw,
            'currency' => $this->currency,
        ];
    }
}
