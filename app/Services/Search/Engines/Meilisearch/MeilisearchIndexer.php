<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\Meilisearch;

use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Meilisearch\Client;

final readonly class MeilisearchIndexer implements Indexer, StopWord
{
    public function __construct(
        private Client $client,
        private Schema $schema
    ) {
    }

    public function createIndex(string $indexName): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);

        $this->client->createIndex($indexName, ['primaryKey' => $schemaConfig->primaryKey]);

        $this->client->index($indexName)->updateSearchableAttributes($this->getSearchableAttributes($schemaConfig));
        $this->client->index($indexName)->updateFilterableAttributes($schemaConfig->facetAttributes);
        $this->client->index($indexName)->updateSortableAttributes($schemaConfig->sortableAttributes);
    }

    public function deleteIndex(string $indexName): void
    {
        $this->client->deleteIndex($indexName);
    }

    public function bulkAdd(string $indexName, array $documents): void
    {
        $this->client->index($indexName)->addDocuments($documents);
    }

    public function bulkUpsert(string $indexName, array $documents): void
    {
        $this->bulkAdd($indexName, $documents);
    }

    public function addDocument(string $indexName, array $document): void
    {
        $this->client->index($indexName)->addDocuments([$document]);
    }

    public function updateDocument(string $indexName, array $document): void
    {
        $this->client->index($indexName)->updateDocuments([$document]);
    }

    public function delete(string $indexName, string $id): void
    {
        $this->client->index($indexName)->deleteDocument($id);
    }

    public function addStopWords(array $stopWords, array $options): void
    {
        $this->client->index($options['index'])->updateStopWords($stopWords);
    }

    /**
     * @throws IndexNotFoundException
     */
    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }

    /**
     * @return List<non-empty-string>
     */
    private function getSearchableAttributes(SchemaConfig $schemaConfig): array
    {
        $weights = [];
        $searchableAttributes = $schemaConfig->searchableAttributes;

        foreach ($schemaConfig->fields as $field) {
            if (!$field->searchable) {
                continue;
            }

            $weights[] = $field->weight;
        }

        $weights = array_filter($weights);

        if (empty($weights)) {
            return $searchableAttributes;
        }

        array_multisort($weights, SORT_DESC, $searchableAttributes);

        return $searchableAttributes;
    }
}
