<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

final class Result
{
    /**
     * @param Hit[] $hits
     * @param int $total
     * @param array<string, Facet> $facets
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total = 0,
        public readonly array $facets = []
    ) {
    }
}
