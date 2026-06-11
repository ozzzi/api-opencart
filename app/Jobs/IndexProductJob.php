<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Chat\Catalog\OpenCartCatalog;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
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

    public function __construct(
        public readonly int $productId,
    ) {
        $this->onQueue('indexing');
    }

    public function handle(
        OpenCartCatalog $catalog,
        EmbeddingClientInterface $embeddingClient,
        OpenSearchIndexer $indexer,
    ): void {
        $documents = $catalog->getProductDocuments($this->productId);

        if ($documents === []) {
            // Product no longer exists — ensure it is absent from the index.
            $indexer->deleteByQuery(
                (string) config('opensearch.indices.products'),
                ['query' => ['term' => ['product_id' => $this->productId]]],
            );

            return;
        }

        $index = (string) config('opensearch.indices.products');

        // Embed all language variants in one batch call.
        $texts = array_map(
            fn (array $doc): string => implode(' ', array_filter([
                $doc['name'],
                $doc['description'],
                $doc['attributes'],
                $doc['category'],
            ])),
            $documents,
        );

        $vectors = $embeddingClient->embed($texts);

        foreach ($documents as $i => $doc) {
            $docId = "{$doc['product_id']}_{$doc['lang']}";

            $indexer->upsert($index, $docId, [
                'product_id'     => $doc['product_id'],
                'lang'           => $doc['lang'],
                'name'           => $doc['name'],
                'description'    => $doc['description'],
                'attributes'     => $doc['attributes'],
                'category'       => $doc['category'],
                'price'          => $doc['price'],
                'in_stock'       => $doc['in_stock'],
                'url'            => $doc['url'],
                'image'          => $doc['image'],
                'content_vector' => $vectors[$i],
            ]);
        }
    }
}
