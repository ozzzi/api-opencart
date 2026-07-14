<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Bot\KnowledgeBaseArticle;
use App\Services\Chat\Catalog\OpenCartCatalog;
use App\Observers\KnowledgeBaseArticleObserver;
use App\Services\Chat\CircuitBreaker\CircuitBreaker;
use App\Services\Chat\CircuitBreaker\CircuitBreakerInterface;
use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\LeadNotifierInterface;
use App\Services\Chat\Contracts\LeadServiceInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Contracts\RateLimiterInterface;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\ConversationService;
use App\Services\Chat\LeadService;
use App\Services\Chat\Llm\FallbackLlmClient;
use App\Services\Chat\Llm\LlmClientWithCircuitBreaker;
use App\Services\Chat\Llm\OpenAiChatClient;
use App\Services\Chat\Notifications\LeadNotifier;
use App\Services\Chat\Notifications\AlertNotifier;
use App\Services\Chat\Notifications\EmailNotificationChannel;
use App\Services\Chat\Notifications\TelegramNotificationChannel;
use App\Services\Chat\Cost\CostCalculator;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\CostTracker;
use App\Services\Chat\LlmOrchestrator;
use App\Services\Chat\RateLimiter;
use App\Services\Chat\Llm\LocalHttpEmbeddingClient;
use App\Services\Chat\Llm\OpenAiEmbeddingClient;
use App\Services\Chat\RetrievalService;
use App\Services\Chat\Search\HybridSearcher;
use App\Services\Chat\Search\OpenSearchClientFactory;
use App\Services\Chat\Search\OpenSearchIndexer;
use App\Services\Chat\ShopAssistant;
use App\Services\Chat\Tools\CompareProductsTool;
use App\Services\Chat\Tools\CreateLeadTool;
use App\Services\Chat\Tools\GetProductDetailsTool;
use App\Services\Chat\Tools\SearchKnowledgeBaseTool;
use App\Services\Chat\Tools\SearchProductsTool;
use App\Services\Chat\Tools\ToolRegistry;
use App\Settings\BotLlmSettings;
use App\Settings\BotRateLimitSettings;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use OpenSearch\Client;

final class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CostTrackerInterface::class, CostTracker::class);

        $this->app->singleton(ConversationServiceInterface::class, ConversationService::class);

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

        $this->app->singleton(OpenCartCatalog::class);
        $this->app->alias(OpenCartCatalog::class, OpenCartCatalogInterface::class);

        $this->app->singleton(OpenSearchIndexer::class);
        $this->app->singleton(HybridSearcher::class);

        $this->app->singleton(RetrievalService::class);
        $this->app->alias(RetrievalService::class, RetrievalServiceInterface::class);

        $this->app->bind(LeadServiceInterface::class, LeadService::class);

        $this->app->bind(LeadNotifierInterface::class, function ($app) {
            return new LeadNotifier([
                $app->make(EmailNotificationChannel::class),
                $app->make(TelegramNotificationChannel::class),
            ]);
        });

        $this->app->singleton(RateLimiter::class, function ($app) {
            return new RateLimiter(
                redis: Redis::connection(),
                settings: $app->make(BotRateLimitSettings::class),
            );
        });
        $this->app->alias(RateLimiter::class, RateLimiterInterface::class);

        $this->app->singleton(CircuitBreakerInterface::class, function () {
            return new CircuitBreaker(Redis::connection());
        });

        $this->app->singleton(ShopAssistantInterface::class, ShopAssistant::class);

        $this->app->singleton(LlmClientInterface::class, function ($app) {
            /** @var BotLlmSettings $llm */
            $llm = $app->make(BotLlmSettings::class);
            $costCalculator = $app->make(CostCalculator::class);
            $circuitBreaker = $app->make(CircuitBreakerInterface::class);
            $apiKey = (string) config('services.openai.api_key');

            $makeClient = static fn (string $model) => new LlmClientWithCircuitBreaker(
                inner: new OpenAiChatClient($costCalculator, $apiKey, $model),
                circuitBreaker: $circuitBreaker,
            );

            return new FallbackLlmClient([
                $makeClient($llm->primaryModel),
                $makeClient($llm->fallbackModel),
            ]);
        });

        $this->app->singleton(ToolRegistryInterface::class, function ($app) {
            return new ToolRegistry([
                $app->make(SearchKnowledgeBaseTool::class),
                $app->make(SearchProductsTool::class),
                $app->make(GetProductDetailsTool::class),
                $app->make(CompareProductsTool::class),
                $app->make(CreateLeadTool::class),
            ]);
        });

        $this->app->singleton(LlmOrchestrator::class);
    }

    public function boot(): void
    {
        KnowledgeBaseArticle::observe(KnowledgeBaseArticleObserver::class);
    }
}
