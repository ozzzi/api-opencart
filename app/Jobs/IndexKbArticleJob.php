<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\KnowledgeBaseArticle;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
use App\Services\Chat\Search\TextChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Carbon;

#[Tries(3)]
#[Backoff([10, 60, 180])]
#[Timeout(120)]
final class IndexKbArticleJob implements ShouldQueue
{
    use Queueable;

    /** Target chunk size in words. */
    private const int CHUNK_SIZE = 400;

    /** Overlap between consecutive chunks in words. */
    private const int CHUNK_OVERLAP = 50;

    /** Maximum chunks per embed() call to avoid oversized HTTP payloads. */
    private const int EMBED_BATCH_SIZE = 32;

    public function __construct(
        public readonly int $articleId,
    ) {
        $this->onQueue('indexing');
    }

    public function handle(
        EmbeddingClientInterface $embeddingClient,
        OpenSearchIndexer $indexer,
        TextChunker $chunker,
    ): void {
        $article = KnowledgeBaseArticle::find($this->articleId);

        if ($article === null) {
            return;
        }

        $index = (string) config('opensearch.indices.kb');

        // Remove all existing chunks for this article before re-indexing
        // so stale chunks from a previously longer version are cleaned up.
        $indexer->deleteByQuery($index, [
            'query' => ['term' => ['article_id' => $article->id]],
        ]);

        $chunks = $chunker->chunk($article->title, $article->content, self::CHUNK_SIZE, self::CHUNK_OVERLAP);

        foreach (array_chunk($chunks, self::EMBED_BATCH_SIZE, preserve_keys: true) as $batch) {
            $texts = array_column($batch, 'text');
            $vectors = $embeddingClient->embed($texts);

            foreach (array_values($batch) as $offset => $chunk) {
                $chunkIndex = $chunk['index'];
                $docId = "{$article->id}_{$chunkIndex}";

                $indexer->upsert($index, $docId, [
                    'article_id'     => $article->id,
                    'chunk_index'    => $chunkIndex,
                    'title'          => $article->title,
                    'content'        => $chunk['text'],
                    'category'       => $article->category,
                    'lang'           => $article->lang,
                    'is_active'      => $article->is_published,
                    'content_vector' => $vectors[$offset],
                ]);
            }
        }

        $article->opensearch_indexed_at = Carbon::now();
        $article->saveQuietly();
    }
}
