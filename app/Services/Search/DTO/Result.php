<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

final readonly class Result
{
    /**
     * @param Hit[] $hits
     * @param int $total
     * @param array<int, List<Facet>> $facets
     * @param string[] $suggest
     * @param array<string, string> $didYouMean
     */
    public function __construct(
        public array $hits,
        public int   $total = 0,
        public array $facets = [],
        public array $suggest = [],
        public array $didYouMean = [],
    ) {
    }
}
