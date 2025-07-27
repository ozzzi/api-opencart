<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Services\Search\Search\SearchBuilder;
use App\Services\Search\SearchManager;
use Illuminate\Http\JsonResponse;

final class SearchController extends Controller
{
    public function __construct(private readonly SearchManager $searchManager)
    {
    }

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $searchBuilder = $this->buildSearch($data);

        return new JsonResponse(data: $searchBuilder->result());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function buildSearch(array $data): SearchBuilder
    {
        $searchBuilder = $this->searchManager->createSearchBuilder($data['index']);

        $this->setQuery($searchBuilder, $data)
            ->setFilters($searchBuilder, $data)
            ->setSorts($searchBuilder, $data)
            ->setPagination($searchBuilder, $data)
            ->setOptions($searchBuilder, $data);

        return $searchBuilder;
    }

    /**
     * @param array<string, string> $data
     */
    private function setQuery(SearchBuilder $searchBuilder, array $data): self
    {
        $searchBuilder->query($data['query']);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setFilters(SearchBuilder $searchBuilder, array $data): self
    {
        $filters = $data['filters'] ?? [];

        foreach ($filters as $filter) {
            $searchBuilder->addFilter(
                attribute: $filter['attribute'],
                value: $filter['value'],
                operator: $filter['operator'] ?? '='
            );
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setSorts(SearchBuilder $searchBuilder, array $data): self
    {
        $sorts = $data['sorts'] ?? [];

        foreach ($sorts as $sort) {
            $searchBuilder->addSort(
                attribute: $sort['attribute'],
                direction: $sort['direction'] ?? 'asc'
            );
        }

        return $this;
    }

    /**
     * @param array<string, int> $data
     */
    private function setPagination(SearchBuilder $searchBuilder, array $data): self
    {
        if (isset($data['limit'])) {
            $searchBuilder->limit($data['limit']);
        }

        if (isset($data['offset'])) {
            $searchBuilder->offset($data['offset']);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setOptions(SearchBuilder $searchBuilder, array $data): self
    {
        $excludeFields = $data['exclude_fields'] ?? [];

        $searchBuilder->options([
            'exclude_fields' => $excludeFields,
        ]);

        return $this;
    }
}
