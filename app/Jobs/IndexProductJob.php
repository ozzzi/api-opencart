<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
use App\Services\Chat\Search\TextChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 60, 180])]
#[Timeout(60)]
final class IndexProductJob implements ShouldQueue
{
    use Queueable;

    private const int CHUNK_SIZE = 400;

    private const int CHUNK_OVERLAP = 50;

    /** Maximum chunks per embed() call to avoid oversized HTTP payloads. */
    private const int EMBED_BATCH_SIZE = 5;

    public function __construct(
        public readonly int $productId,
    ) {
        $this->onQueue('indexing');
    }

    public function handle(
        OpenCartCatalogInterface $catalog,
        EmbeddingClientInterface $embeddingClient,
        OpenSearchIndexer $indexer,
        TextChunker $chunker,
    ): void {
        $index = (string) config('opensearch.indices.products');

        $documents = $catalog->getProductDocuments($this->productId);

        $indexer->deleteByQuery(
            $index,
            ['query' => ['term' => ['product_id' => $this->productId]]],
        );

        if ($documents === []) {
            return;
        }

        $chunksByDoc = array_map(
            static fn (array $doc): array => $chunker->chunk(
                "{$doc['name']} {$doc['category']}",
                $doc['description'],
                self::CHUNK_SIZE,
                self::CHUNK_OVERLAP,
            ),
            $documents,
        );

        $pending = [];

        foreach ($documents as $i => $doc) {
            foreach ($chunksByDoc[$i] as $chunk) {
                if (mb_trim($chunk['text']) === '') {
                    continue;
                }

                $pending[] = ['doc' => $doc, 'chunk' => $chunk];
            }
        }

        foreach (array_chunk($pending, self::EMBED_BATCH_SIZE, preserve_keys: true) as $batch) {
            $texts = array_map(static fn (array $item): string => $item['chunk']['text'], $batch);
            $vectors = $embeddingClient->embed($texts);

            foreach (array_values($batch) as $offset => $item) {
                $doc = $item['doc'];
                $chunkIndex = $item['chunk']['index'];
                $docId = "{$doc['product_id']}_{$doc['lang']}_{$chunkIndex}";

                $indexer->upsert($index, $docId, [
                    'product_id'     => $doc['product_id'],
                    'chunk_index'    => $chunkIndex,
                    'lang'           => $doc['lang'],
                    'name'           => $doc['name'],
                    'description'    => $item['chunk']['text'],
                    'attributes'     => $doc['attributes'],
                    'category'       => $doc['category'],
                    'price'          => $doc['price'],
                    'in_stock'       => $doc['in_stock'],
                    'url'            => $doc['url'],
                    'image'          => $doc['image'],
                    'content_vector' => $vectors[$offset],
                ]);
            }
        }
    }
}
