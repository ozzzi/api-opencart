<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Notifications\AlertNotifier;
use App\Services\Chat\Notifications\EmailNotificationChannel;
use App\Services\Chat\Notifications\TelegramNotificationChannel;
use App\Services\Chat\Cost\CostCalculator;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Llm\LocalHttpEmbeddingClient;
use App\Services\Chat\Llm\OpenAiEmbeddingClient;
use App\Services\Chat\RetrievalService;
use App\Services\Chat\Search\HybridSearcher;
use App\Services\Chat\Search\OpenSearchClientFactory;
use App\Services\Chat\Search\OpenSearchIndexer;
use App\Settings\BotLlmSettings;
use Illuminate\Support\ServiceProvider;
use OpenSearch\Client;

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

        $this->app->singleton(Client::class, fn () => OpenSearchClientFactory::make());

        $this->app->singleton(EmbeddingClientInterface::class, function ($app) {
            /** @var BotLlmSettings $llm */
            $llm = $app->make(BotLlmSettings::class);

            if ($llm->embeddingProvider === 'openai') {
                return new OpenAiEmbeddingClient(
                    costTracker: $app->make(CostTrackerInterface::class),
                    costCalculator: $app->make(CostCalculator::class),
                    apiKey: (string) config('services.openai.api_key'),
                    baseUrl: (string) config('services.openai.base_url'),
                    model: $llm->embeddingModel,
                    dimensions: $llm->embeddingDimensions,
                );
            }

            return new LocalHttpEmbeddingClient(
                url: (string) config('services.search.embedder_url'),
                token: (string) config('services.search.embedder_token'),
                model: $llm->embeddingModel,
                dimensions: $llm->embeddingDimensions,
            );
        });

        $this->app->singleton(OpenSearchIndexer::class);
        $this->app->singleton(HybridSearcher::class);
        $this->app->singleton(RetrievalService::class);

        // Phase 1: LlmClientInterface, FallbackLlmClient, CircuitBreaker
        // Phase 4: ToolRegistry and individual tools
    }

    public function boot(): void
    {
        // Phase 3: KnowledgeBaseArticleObserver
    }
}
