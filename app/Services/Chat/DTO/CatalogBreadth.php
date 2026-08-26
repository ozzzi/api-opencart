<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

/**
 * A slice of the catalog for one query: how many distinct products match, how
 * they split across categories and price, and a sample of their names.
 */
final readonly class CatalogBreadth
{
    /**
     * Deliberately no category facet. `category` is stored per language, so with
     * a language-agnostic search the same category is counted twice under two
     * names ("Обличчя" and "Лицо"), and OpenCart's service categories ("Out of
     * stock") outrank the real ones. A facet the customer would have to be asked
     * about must be language-independent; price is, and the distinguishing words
     * a question is really built from live in the names anyway.
     *
     * @param list<array{from?: float, to?: float, count: int}>      $priceRanges
     * @param array{min: float, max: float, avg: float}|null         $priceStats
     * @param list<string>                                           $sampleNames
     */
    public function __construct(
        public int $totalHits,
        public array $priceRanges = [],
        public ?array $priceStats = null,
        public array $sampleNames = [],
    ) {
    }

    /**
     * Used when the probe cannot run (OpenSearch unavailable, malformed reply).
     * A breadth of zero never trips the gate, so a failed probe degrades to the
     * pre-existing behaviour instead of blocking the answer.
     */
    public static function empty(): self
    {
        return new self(totalHits: 0);
    }
}
