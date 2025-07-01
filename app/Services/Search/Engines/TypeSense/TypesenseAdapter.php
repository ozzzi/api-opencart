<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\TypeSense;

use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\Searcher;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\Schema\Schema;
use Typesense\Client;

final readonly class TypesenseAdapter implements AdapterInterface
{
    public function __construct(
        private Client $client,
        private Schema $schema
    ) {
    }

    public function getIndexer(): Indexer&StopWord
    {
        return new TypesenseIndexer($this->client, $this->schema);
    }

    public function getSearcher(): Searcher
    {
        return new TypesenseSearcher($this->client, $this->schema);
    }
}
