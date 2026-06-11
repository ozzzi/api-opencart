<?php

declare(strict_types=1);

namespace App\Console\Commands\Chat;

use App\Jobs\IndexKbArticleJob;
use App\Jobs\IndexProductJob;
use App\Models\Bot\KnowledgeBaseArticle;
use App\Models\OpenCart\OcProduct;
use App\Services\Chat\Search\OpenSearchIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('chat:catalog-reindex {--products-only : Reindex products only} {--kb-only : Reindex knowledge base only}')]
#[Description('Full reindex of the product catalog and/or knowledge base. Required after changing the embedding model.')]
final class CatalogReindex extends Command
{
    public function __construct(private readonly OpenSearchIndexer $indexer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productsOnly = (bool) $this->option('products-only');
        $kbOnly = (bool) $this->option('kb-only');

        if (! $productsOnly && ! $kbOnly) {
            $productsOnly = true;
            $kbOnly = true;
        }

        if ($productsOnly) {
            $this->reindexProducts();
        }

        if ($kbOnly) {
            $this->reindexKb();
        }

        $this->newLine();
        $this->info('Done. Jobs dispatched — monitor progress via Horizon.');

        return self::SUCCESS;
    }

    private function reindexProducts(): void
    {
        $this->line('  <fg=yellow>Clearing</> products_index…');

        $this->indexer->deleteByQuery(
            (string) config('opensearch.indices.products'),
            ['query' => ['match_all' => (object) []]],
        );

        // Reset incremental sync cursor so the next scheduled sync doesn't skip anything.
        Cache::forget('chat:catalog:last_sync_at');

        $count = 0;

        OcProduct::active()->orderBy('product_id')->chunk(100, function ($products) use (&$count): void {
            foreach ($products as $product) {
                IndexProductJob::dispatch($product->product_id);
                $count++;
            }
        });

        $this->line("  <fg=green>Queued</> {$count} products for indexing.");
    }

    private function reindexKb(): void
    {
        $this->line('  <fg=yellow>Clearing</> kb_index…');

        $this->indexer->deleteByQuery(
            (string) config('opensearch.indices.kb'),
            ['query' => ['match_all' => (object) []]],
        );

        $count = 0;

        KnowledgeBaseArticle::query()->orderBy('id')->chunk(100, function ($articles) use (&$count): void {
            foreach ($articles as $article) {
                IndexKbArticleJob::dispatch($article->id);
                $count++;
            }
        });

        $this->line("  <fg=green>Queued</> {$count} KB articles for indexing.");
    }
}
