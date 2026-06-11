<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use OpenSearch\Client;
use RuntimeException;

/**
 * Low-level write layer over the OpenSearch PHP client.
 *
 * Provides idempotent upsert (index with explicit _id), delete by id,
 * delete by query, and bulk variants.  All methods throw RuntimeException
 * on OpenSearch errors so callers can decide on retry/logging strategy.
 */
final class OpenSearchIndexer
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Upsert a single document.  If a document with $id already exists it is
     * replaced entirely (not merged).
     *
     * @param array<string, mixed> $document
     */
    public function upsert(string $index, string $id, array $document): void
    {
        $response = $this->client->index([
            'index' => $index,
            'id'    => $id,
            'body'  => $document,
        ]);

        if (! in_array($response['result'] ?? '', ['created', 'updated'], strict: true)) {
            throw new RuntimeException(
                "OpenSearch upsert failed for {$index}/{$id}: ".json_encode($response),
            );
        }
    }

    /**
     * Delete a single document by id.  Silently ignores 404 (already absent).
     */
    public function delete(string $index, string $id): void
    {
        try {
            $this->client->delete(['index' => $index, 'id' => $id]);
        } catch (\OpenSearch\Common\Exceptions\Missing404Exception) {
            // Document was already absent — treat as success.
        }
    }

    /**
     * Delete all documents matching an arbitrary query body.
     *
     * Example query:
     *   ['query' => ['term' => ['product_id' => 42]]]
     *
     * @param array<string, mixed> $query
     */
    public function deleteByQuery(string $index, array $query): void
    {
        $this->client->deleteByQuery([
            'index' => $index,
            'body'  => $query,
        ]);
    }

    /**
     * Upsert multiple documents in a single bulk request.
     *
     * @param array<string, array<string, mixed>> $documents  Keys are document ids.
     */
    public function bulkUpsert(string $index, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $body = [];

        foreach ($documents as $id => $document) {
            $body[] = ['index' => ['_index' => $index, '_id' => (string) $id]];
            $body[] = $document;
        }

        $response = $this->client->bulk(['body' => $body]);

        if ($response['errors'] ?? false) {
            throw new RuntimeException(
                "Bulk upsert to {$index} contained errors: ".json_encode($response['items'] ?? []),
            );
        }
    }

    /**
     * Delete multiple documents by their ids in a single bulk request.
     *
     * @param list<string> $ids
     */
    public function bulkDelete(string $index, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $body = array_map(
            fn (string $id) => ['delete' => ['_index' => $index, '_id' => $id]],
            $ids,
        );

        $this->client->bulk(['body' => $body]);
    }
}
