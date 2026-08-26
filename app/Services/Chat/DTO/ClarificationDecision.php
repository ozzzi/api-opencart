<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

/**
 * What the clarification gate decided for one search_products call.
 *
 * Three outcomes, in order of precedence:
 *   - ask()       — the query does not discriminate the catalog; return the
 *                   breadth slice and no products at all;
 *   - diversify() — the query is still broad but the round limit is spent, so
 *                   show a cut across categories instead of a flat top-N;
 *   - proceed()   — ordinary search.
 */
final readonly class ClarificationDecision
{
    private function __construct(
        public bool $needsClarification,
        public bool $diversify,
        public ?CatalogBreadth $breadth = null,
        public int $round = 0,
    ) {
    }

    public static function proceed(): self
    {
        return new self(needsClarification: false, diversify: false);
    }

    public static function diversify(): self
    {
        return new self(needsClarification: false, diversify: true);
    }

    public static function ask(CatalogBreadth $breadth, int $round): self
    {
        return new self(
            needsClarification: true,
            diversify: false,
            breadth: $breadth,
            round: $round,
        );
    }
}
