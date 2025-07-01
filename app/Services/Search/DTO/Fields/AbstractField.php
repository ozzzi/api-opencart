<?php

declare(strict_types=1);

namespace App\Services\Search\DTO\Fields;

abstract class AbstractField
{
    /**
     * @param string $name
     * @param string $type
     * @param bool $searchable
     * @param bool $facet
     * @param bool $sortable
     * @param array<string, mixed> $options
     * @param int $weight
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $searchable,
        public readonly bool $facet,
        public readonly bool $sortable,
        public readonly array $options,
        public readonly int $weight,
    ) {
    }
}
