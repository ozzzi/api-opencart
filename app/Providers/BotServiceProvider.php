<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Notifications\AlertNotifier;
use App\Services\Chat\Notifications\EmailNotificationChannel;
use App\Services\Chat\Notifications\TelegramNotificationChannel;
use Illuminate\Support\ServiceProvider;

final class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AlertNotifierInterface::class, function ($app) {
            return new AlertNotifier([
                $app->make(EmailNotificationChannel::class),
                $app->make(TelegramNotificationChannel::class),
            ]);
        });

        // Phase 1: LlmClientInterface, FallbackLlmClient, CircuitBreaker
        // Phase 3: EmbeddingClientInterface, OpenSearch\Client, OpenSearchIndexer, HybridSearcher
        // Phase 4: ToolRegistry and individual tools
    }

    public function boot(): void
    {
        // Phase 3: KnowledgeBaseArticleObserver
    }
}
