<?php

declare(strict_types=1);

namespace App\Services\Search\DTO\Fields;

final class StringField extends AbstractField
{
    /**
     * {@inheritdoc}
     */
    public function __construct(
        string $name,
        bool $searchable = true,
        bool $facet = false,
        bool $sortable = false,
        array $options = [],
        int $weight = 0,
    ) {
        parent::__construct(
            name: $name,
            type: 'string',
            searchable: $searchable,
            facet: $facet,
            sortable: $sortable,
            options: $options,
            weight: $weight,
        );
    }
}
