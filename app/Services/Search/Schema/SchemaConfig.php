<?php

declare(strict_types=1);

namespace App\Services\Search\Schema;

use App\Services\Search\DTO\Fields\AbstractField;

final readonly class SchemaConfig
{
    /**
     * @var List<non-empty-string>
     */
    public array $searchableAttributes;

    /**
     * @var List<non-empty-string>
     */
    public array $facetAttributes;

    /**
     * @var List<non-empty-string>
     */
    public array $sortableAttributes;

    /**
     * @param string|int $primaryKey
     * @param AbstractField[] $fields
     * @param int|array<string> $typoTolerance
     */
    public function __construct(
        public string|int $primaryKey,
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
     * @return array<string, List<non-empty-string>>
     */
    private function getAttributes(array $fields): array
    {
        $attributes = [
            'searchableAttributes' => [],
            'facetAttributes' => [],
        ];

        foreach ($fields as $field) {
            if (!$field->name) {
                continue;
            }

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
