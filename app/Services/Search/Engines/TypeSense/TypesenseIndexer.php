<?php

declare(strict_types=1);

namespace App\Services\Search\Engines\TypeSense;

use App\Services\Search\Contracts\Indexer;
use App\Services\Search\Contracts\StopWord;
use App\Services\Search\DTO\Fields\AbstractField;
use App\Services\Search\Exceptions\IndexNotFoundException;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

final readonly class TypesenseIndexer implements Indexer, StopWord
{
    public function __construct(
        private Client $client,
        private Schema $schema
    ) {
    }

    public function createIndex(string $indexName): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);
        $fields = $this->createIndexFields($schemaConfig->fields);

        $schema = [
            'name' => $indexName,
            'fields' => $fields,
        ];

        $this->client->collections->create($schema);
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client->collections[$indexName]->delete();
        } catch (ObjectNotFound) {
            throw new IndexNotFoundException('Index not found');
        }
    }

    public function bulkAdd(string $indexName, array $documents): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);

        foreach ($documents as &$document) {
            $document['id'] = (string) ($document[$schemaConfig->primaryKey] ?? '');
        }

        unset($document);

        $this->client->collections[$indexName]->documents->import($documents);
    }

    /**
     * @param array<int, array<string, string|int|float>> $documents
     */
    public function bulkUpsert(string $indexName, array $documents): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);

        foreach ($documents as &$document) {
            $document['id'] = (string) ($document[$schemaConfig->primaryKey] ?? '');
        }

        unset($document);

        $this->client->collections[$indexName]->documents->import($documents, ['action' => 'emplace']);
    }

    public function addDocument(string $indexName, array $document): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);
        $document['id'] = (string) ($document[$schemaConfig->primaryKey] ?? '');

        $this->client->collections[$indexName]->documents->create($document);
    }

    public function updateDocument(string $indexName, array $document): void
    {
        $schemaConfig = $this->getSchemaConfig($indexName);
        $document['id'] = (string) ($document[$schemaConfig->primaryKey] ?? '');

        $this->client->collections[$indexName]->documents->update($document);
    }

    public function delete(string $indexName, string $id): void
    {
        try {
            $this->client->collections[$indexName]->documents[$id]->delete();
        } catch (ObjectNotFound) {
            throw new IndexNotFoundException('Index not found');
        }
    }

    /**
     * @param List<string> $stopWords
     * @param array<string, string> $options
     */
    public function addStopWords(array $stopWords, array $options): void
    {
        $this->client->stopwords->put([
            'name' => $options['name'] ?? 'stopword_set',
            'stopwords' => $stopWords,
        ]);
    }

    /**
     * @throws IndexNotFoundException
     */
    private function getSchemaConfig(string $indexName): SchemaConfig
    {
        return $this->schema->schemas[$indexName] ?? throw new IndexNotFoundException('Index not found');
    }

    /**
     * @param AbstractField[] $indexFields
     * @return List<array<string, string|bool>>
     */
    private function createIndexFields(array $indexFields): array
    {
        $fields = [];

        // TODO multiple types string[] etc
        foreach ($indexFields as $field) {
            $fields[] = array_merge([
                'name' => $field->name,
                'type' => $field->type,
                'facet' => $field->facet,
                'sort' => $field->sortable,
            ], $field->options ?? []);
        }

        return $fields;
    }
}
