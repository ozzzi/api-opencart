<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\IndexKbArticleJob;
use App\Models\Bot\KnowledgeBaseArticle;
use App\Services\Chat\Search\OpenSearchIndexer;

final class KnowledgeBaseArticleObserver
{
    public function __construct(private readonly OpenSearchIndexer $indexer)
    {
    }

    /**
     * Dispatch re-indexing whenever an article is created or updated.
     */
    public function saved(KnowledgeBaseArticle $article): void
    {
        IndexKbArticleJob::dispatch($article->id);
    }

    /**
     * Remove all index chunks for the article on hard delete.
     */
    public function deleted(KnowledgeBaseArticle $article): void
    {
        $this->indexer->deleteByQuery(
            (string) config('opensearch.indices.kb'),
            ['query' => ['term' => ['article_id' => $article->id]]],
        );
    }

    /**
     * Re-index after a soft-deleted article is restored.
     */
    public function restored(KnowledgeBaseArticle $article): void
    {
        IndexKbArticleJob::dispatch($article->id);
    }
}
