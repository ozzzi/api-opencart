<?php

declare(strict_types=1);

namespace App\Services\Chat\Catalog\Contracts;

/**
 * Turns a raw catalog price into the display string shown on a product card.
 *
 * Formatting happens on the backend so the widget never has to know about
 * currency symbols, separators or exchange rates (task-structured-output.md §2.4).
 */
interface PriceFormatterInterface
{
    /** Format a base-currency amount for display, e.g. 28990.0 → "28,990 ₴". */
    public function format(float $amount): string;

    /** ISO 4217 code of the storefront's display currency, e.g. "UAH". */
    public function currencyCode(): string;
}
