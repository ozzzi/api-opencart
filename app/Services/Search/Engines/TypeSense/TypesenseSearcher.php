<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\TypeSense;

use App\Services\Search\Contracts\Searcher;
use App\Services\Search\DTO\Facet;
use App\Services\Search\DTO\Hit;
use App\Services\Search\DTO\Result;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Exceptions\SearchException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Typesense\Client;
use InvalidArgumentException;
use Throwable;

use function array_filter;
use function in_array;
use function is_bool;
use function is_string;

final class TypesenseSearcher implements Searcher
{
    public function __construct(
        private readonly Client $client,
        private readonly Schema $schema
    ) {
    }

    public function search(
        string $indexName,
        string $query = '',
        array $filters = [],
        array $sorts = [],
        int $limit = 10,
        int $offset = 0,
        array $options = [],
    ): Result {
        $schemaConfig = $this->getSchemaConfig($indexName);

        $searchParams = [
            'q' => $query ? \mb_strtolower($query) : '*',
            'query_by'  => \implode(',', $schemaConfig->searchableAttributes),
        ];

        if ($weights = $this->getWeights($schemaConfig)) {
            $searchParams['query_by_weights'] = $weights;
        }

        if (count($filters)) {
            $searchParams['filter_by'] = $this->buildFilters($filters);
        }

        if (count($schemaConfig->facetAttributes)) {
            $searchParams['facet_by'] = \implode(',', $schemaConfig->facetAttributes);
        }

        if (count($sorts)) {
            $searchParams['sort_by'] = $this->buildSorts($sorts);
        }

        if ($offset !== 0) {
            $searchParams['page'] = floor($offset / $limit) + 1;
        }

        $searchParams['per_page'] = $limit;

        $searchParams['num_typos'] = is_array($schemaConfig->typoTolerance) ?
            \implode(',', $schemaConfig->typoTolerance) :
            $schemaConfig->typoTolerance;

        $searchParams = array_merge($searchParams, $options);

        try {
            /** @var array{hits: array<int, array<string, mixed>>, found: int, facet_counts: array<int, array<string, mixed>>} $results */
            $results = $this->client->collections[$indexName]->documents->search($searchParams);
        } catch (Throwable $e) {
            throw  new SearchException($e->getMessage(), $e->getCode(), $e);
        }

        return new Result(
            hits: $this->getHits($results['hits']),
            total: (int) ($results['found']),
            facets: $this->getFacets($results['facet_counts']),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @return string
     */
    private function buildFilters(array $filters): string
    {
        $formattedFilters = [];

        foreach ($filters as $filter) {
            $formattedFilters[] = $this->processFilterField(
                attribute: (string) $filter['attribute'],
                value: $filter['value'],
                operator: (string) ($filter['operator'] ?? '='),
            );
        }

        return \implode('&&', $formattedFilters);
    }

    private function processFilterField(string $attribute, mixed $value, string $operator = '='): string
    {
        $validOperators = ['=', '!=', '>', '<', '>=', '<='];

        if (!in_array($operator, $validOperators, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        $attribute = mb_strtolower($attribute);
        $value = is_array($value) ? $this->processArrayFilterValue($value) : $this->escapeFilterValue($value);

        return "{$attribute}:{$operator}{$value}";
    }

    /**
     * @param array<int|string, mixed> $value
     * @return string
     */
    private function processArrayFilterValue(array $value): string
    {
        $formattedValue = array_map(fn ($item) => $this->escapeFilterValue($item), $value);

        return '[' . implode(',', $formattedValue) . ']';
    }

    private function escapeFilterValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $this->escapeStringValue($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function escapeStringValue(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $specialChars = [':' => '\:', '&' => '\&', '|' => '\|', '(' => '\(', ')' => '\)', ' ' => '\ ', '"' => '\"', "'" => "\'"];

        $result = '';

        if ($chars === false) {
            return $value;
        }

        foreach ($chars as $char) {
            $result .= $specialChars[$char] ?? $char;
        }

        return $result;
    }

    /**
     * @param array<string, string> $sorts
     * @return string
     */
    private function buildSorts(array $sorts): string
    {
        $formattedSorts = [];

        foreach ($sorts as $attribute => $direction) {
            $direction = \mb_strtolower($direction);
            $formattedSorts[] = "{$attribute}:{$direction}";
        }

        return \implode(',', $formattedSorts);

    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, Hit>
     */
    private function getHits(array $results): array
    {
        $hits = [];

        foreach ($results as $result) {
            $hits[] = new Hit(
                id: $result['document']['id'] ?? 'id',
                document: $result['document'],
                score: (float) ($result['text_match_info']['score'] ?? 0),
                highlight: $result['highlights'][0]['snippet'] ?? null,
            );
        }

        return $hits;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, List<Facet>>
     */
    private function getFacets(array $results): array
    {
        $facets = [];

        foreach ($results as $result) {
            foreach ($result['counts'] as $item) {
                if (!isset($item['count'], $item['value'])) {
                    continue;
                }

                $facets[$result['field_name']][] = new Facet(
                    count: $item['count'],
                    value: $item['value'],
                );
            }
        }

        return $facets;
    }

    private function getWeights(SchemaConfig $schemaConfig): ?string
    {
        $weights = [];

        foreach ($schemaConfig->fields as $field) {
            $weights[] = $field->weight;
        }

        $weights = array_filter($weights);

        if (count($weights) !== count($schemaConfig->searchableAttributes)) {
            return null;
        }

        return \implode(',', $weights);
    }

    /**
     * @throws IndexNotFoundException
     */
    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }
}
