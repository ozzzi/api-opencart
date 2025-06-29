<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

interface AdapterInterface
{
    public function getIndexer(): Indexer&StopWord;

    public function getSearcher(): Searcher;
}
