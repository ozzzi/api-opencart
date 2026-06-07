<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 1: LlmClientInterface, FallbackLlmClient, CircuitBreaker
        // Phase 3: EmbeddingClientInterface, OpenSearch\Client, OpenSearchIndexer, HybridSearcher
        // Phase 4: ToolRegistry and individual tools
    }

    public function boot(): void
    {
        // Phase 3: KnowledgeBaseArticleObserver
    }
}
