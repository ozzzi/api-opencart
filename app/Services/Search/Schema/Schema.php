<?php

declare(strict_types=1);

namespace App\Services\Search\Schema;

final readonly class Schema
{
    /**
     * @param array<string, SchemaConfig> $schemas
     */
    public function __construct(
        public array $schemas
    ) {
    }
}
