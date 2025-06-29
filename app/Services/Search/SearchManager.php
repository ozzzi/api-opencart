<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\Searcher;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\Search\SearchBuilder;

final readonly class SearchManager
{
    public function __construct(
        private AdapterInterface $adapter,
    ) {
    }

    public function getIndexer(): Indexer&StopWord
    {
        return $this->adapter->getIndexer();
    }

    public function getSearcher(): Searcher
    {
        return $this->adapter->getSearcher();
    }

    public function createSearchBuilder(string $indexName): SearchBuilder
    {
        return new SearchBuilder(
            indexName: $indexName,
            searcher: $this->getSearcher()
        );
    }
}
