<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

interface Indexer
{
    public function createIndex(string $indexName): void;

    public function deleteIndex(string $indexName): void;

    /**
     * @param array<int, array<string, string|int|float>> $documents
     */
    public function bulkAdd(string $indexName, array $documents): void;

    /**
     * @param array<int, array<string, string|int|float>> $documents
     */
    public function bulkUpsert(string $indexName, array $documents): void;

    /**
     * @param array<string, string|int|float> $document
     */
    public function addDocument(string $indexName, array $document): void;

    /**
     * @param array<string, string|int|float> $document
     */
    public function updateDocument(string $indexName, array $document): void;

    public function delete(string $indexName, string $id): void;
}
