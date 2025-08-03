<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\Opensearch;

use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\Searcher;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\Schema\Schema;
use OpenSearch\Client;

final readonly class OpensearchAdapter implements AdapterInterface
{
    public function __construct(
        public Client $client,
        private Schema $schema
    ) {
    }

    public function getIndexer(): Indexer&StopWord
    {
        return new OpensearchIndexer($this->client, $this->schema);
    }

    public function getSearcher(): Searcher
    {
        return new OpensearchSearcher($this->client, $this->schema);
    }
}
