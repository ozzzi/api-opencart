<?php

declare(strict_types=1);

namespace App\Services\Search\DTO\Fields;

final class IntegerField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    public function __construct(
        string $name,
        bool $searchable = false,
        bool $facet = false,
        bool $sortable = false,
        array $options = [],
        int $weight = 0,
    ) {
        parent::__construct(
            name: $name,
            type: 'int64',
            searchable: $searchable,
            facet: $facet,
            sortable: $sortable,
            options: $options,
            weight: $weight,
        );
    }
}
