<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
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

    /**
     * Hard cap on the text sent to the embeddings API.
     *
     * OpenAI's text-embedding-3-* models accept 8192 tokens; 8000 characters
     * stays under that even in the worst case of one token per Cyrillic
     * character. Only the embedding payload is truncated — the full
     * description is still stored in the index for BM25.
     */
    private const int MAX_EMBED_CHARS = 8000;

    public function __construct(
        public readonly int $productId,
    ) {
        $this->onQueue('indexing');
    }

    public function handle(
        OpenCartCatalogInterface $catalog,
        EmbeddingClientInterface $embeddingClient,
        OpenSearchIndexer $indexer,
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

        // Embed all language variants in one batch call.
        $texts = array_map($this->buildEmbeddingText(...), $documents);

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

    /**
     * Build the text embedded for a single language variant.
     *
     * Short high-signal fields come first so truncation only ever eats the
     * tail of the description.
     *
     * @param array{name:string,description:string,attributes:string,category:string} $doc
     */
    private function buildEmbeddingText(array $doc): string
    {
        $text = implode(' ', array_filter([
            $doc['name'],
            $doc['category'],
            $doc['attributes'],
            $doc['description'],
        ]));

        return mb_substr($text, 0, self::MAX_EMBED_CHARS);
    }
}
