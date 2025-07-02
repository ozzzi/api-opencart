<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\Meilisearch;

use App\Services\Search\Contracts\Searcher;
use App\Services\Search\DTO\Hit;
use App\Services\Search\DTO\Result;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Exceptions\SearchException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Meilisearch\Client;
use InvalidArgumentException;
use Throwable;

use function in_array;
use function is_bool;
use function is_string;

final readonly class MeilisearchSearcher implements Searcher
{
    public function __construct(
        private Client $client,
        private Schema $schema
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
            'showRankingScore' => true,
            'attributesToHighlight' => ['*'],
            'attributesToSearchOn' => $schemaConfig->searchableAttributes,
        ];

        if (count($filters)) {
            $searchParams['filter'] = $this->buildFilters($filters);
        }

        if (count($schemaConfig->facetAttributes)) {
            $searchParams['facets'] = $schemaConfig->facetAttributes;
        }

        if (count($sorts)) {
            $searchParams['sort'] = $this->buildSorts($sorts);
        }

        $searchParams['hitsPerPage'] = $limit;

        if ($offset !== 0) {
            $searchParams['page'] = $offset;
        }

        $searchParams = array_merge($searchParams, $options);

        try {
            $results = $this->client->index($indexName)->search($query, $searchParams);
        } catch (Throwable $e) {
            throw  new SearchException($e->getMessage(), $e->getCode(), $e);
        }

        return new Result(
            hits: $this->getHits($results->getHits(), $schemaConfig),
            total: $results->count(),
            facets: $results->getFacetDistribution(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @return List<List<string>|string>
     */
    private function buildFilters(array $filters): array
    {
        $formattedFilters = [];

        foreach ($filters as $filter) {
            $formattedFilters[] = $this->processFilterField(
                attribute: $filter['attribute'],
                value: $filter['value'],
                operator: $filter['operator'] ?? '=',
            );
        }

        return $formattedFilters;
    }

    /**
     * @param string|int|float|List<string> $value
     * @return string|List<string>
     */
    private function processFilterField(string $attribute, mixed $value, string $operator = '='): string|array
    {
        $validOperators = ['=', '!=', '>', '<', '>=', '<='];

        if (!in_array($operator, $validOperators)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        $attribute = mb_strtolower($attribute);

        if (is_array($value)) {
            return $this->processArrayFilterValue($attribute, $value);
        }

        $value = $this->escapeFilterValue($value);
        return "{$attribute} {$operator} {$value}";
    }

    /**
     * @param List<string> $values
     * @return List<string>
     */
    private function processArrayFilterValue(string $attribute, array $values): array
    {
        $filter = [];

        foreach ($values as $item) {
            $value = $this->escapeFilterValue($item);
            $filter[] = "{$attribute} = {$value}";
        }

        return $filter;
    }

    private function escapeFilterValue(string|int|float|bool $value): string
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
     * @return List<string>
     */
    private function buildSorts(array $sorts): array
    {
        $formattedSorts = [];

        foreach ($sorts as $attribute => $direction) {
            $direction = \mb_strtolower($direction);
            $formattedSorts[] = "{$attribute}:{$direction}";
        }

        return $formattedSorts;

    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, Hit>
     */
    private function getHits(array $results, SchemaConfig $schemaConfig): array
    {
        $hits = [];

        foreach ($results as $result) {
            $hits[] = new Hit(
                id: $result[$schemaConfig->primaryKey],
                document: $result,
                score: (float) ($result['_rankingScore'] ?? 0),
                highlight: null,
            );
        }

        return $hits;
    }

    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }
}
