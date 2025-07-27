<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

final class Facet
{
    public function __construct(
        public readonly int $count,
        public readonly string|int $value
    ) {
    }
}
