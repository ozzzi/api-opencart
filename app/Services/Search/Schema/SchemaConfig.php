<?php

declare(strict_types=1);

namespace App\Services\Search\Schema;

use App\Services\Search\DTO\Fields\AbstractField;

final readonly class SchemaConfig
{
    /**
     * @var List<string>
     */
    public array $searchableAttributes;

    /**
     * @var List<string>
     */
    public array $facetAttributes;

    /**
     * @var List<string>
     */
    public array $sortableAttributes;

    /**
     * @param string $primaryKey
     * @param AbstractField[] $fields
     * @param int|array<string> $typoTolerance
     */
    public function __construct(
        public string    $primaryKey,
        public array     $fields,
        public int|array $typoTolerance = 2,
    ) {
        $attributes = $this->getAttributes($this->fields);
        $this->searchableAttributes = $attributes['searchableAttributes'];
        $this->facetAttributes = $attributes['facetAttributes'];
        $this->sortableAttributes = $attributes['sortableAttributes'];
    }

    /**
     * @param AbstractField[] $fields
     * @return array<string, List<string>>
     */
    private function getAttributes(array $fields): array
    {
        $attributes = [
            'searchableAttributes' => [],
            'facetAttributes' => [],
        ];

        foreach ($fields as $field) {
            if ($field->searchable) {
                $attributes['searchableAttributes'][] = $field->name;
            }

            if ($field->facet) {
                $attributes['facetAttributes'][] = $field->name;
            }

            if ($field->sortable) {
                $attributes['sortableAttributes'][] = $field->name;
            }
        }

        return $attributes;
    }
}
