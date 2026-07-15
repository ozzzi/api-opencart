<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\KnowledgeBaseArticle;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
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

        $chunks = $this->splitIntoChunks($article->title, $article->content);

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

    /**
     * Split article text into overlapping word-based chunks.
     *
     * Each element: ['index' => int, 'text' => string]
     *
     * @return list<array{index:int,text:string}>
     */
    private function splitIntoChunks(string $title, string $content): array
    {
        $words = preg_split('/\s+/u', mb_trim($content), flags: PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return [['index' => 0, 'text' => mb_trim($title)]];
        }

        $step = max(1, self::CHUNK_SIZE - self::CHUNK_OVERLAP);
        $total = count($words);
        $chunks = [];
        $chunkIndex = 0;

        for ($start = 0; $start < $total; $start += $step) {
            $slice = array_slice($words, $start, self::CHUNK_SIZE);
            $text = $title.' '.implode(' ', $slice);

            $chunks[] = ['index' => $chunkIndex, 'text' => mb_trim($text)];
            $chunkIndex++;

            // If the remaining words fit entirely in this chunk, we're done.
            if ($start + self::CHUNK_SIZE >= $total) {
                break;
            }
        }

        return $chunks;
    }
}
