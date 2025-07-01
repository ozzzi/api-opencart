<?php

declare(strict_types=1);

namespace App\Services\Search\Search;

use App\Services\Search\Contracts\Searcher;
use App\Services\Search\DTO\Result;

final class SearchBuilder
{
    private string $query = '';

    /**
     * @var array<int, array<string, string|int|float>>
     */
    private array $filters = [];

    /**
     * @var array<string, string>
     */
    private array $sorts = [];

    private int $limit = 10;

    private int $offset = 0;

    /**
     * @var array<string, string>
     */
    private array $options = [];

    public function __construct(
        private readonly string $indexName,
        private readonly Searcher $searcher
    ) {
    }

    public function query(string $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function addFilter(string $attribute, string|int|float $value, string $operator = '='): self
    {
        $this->filters[] = [
            'attribute' => $attribute,
            'value' => $value,
            'operator' => $operator,
        ];

        return $this;
    }

    public function addSort(string $attribute, string $direction = 'asc'): self
    {
        $this->sorts[$attribute] = $direction;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function result(): Result
    {
        return $this->searcher->search(
            indexName: $this->indexName,
            query: $this->query,
            filters: $this->filters,
            sorts: $this->sorts,
            limit: $this->limit,
            offset: $this->offset,
            options: $this->options,
        );
    }
}
