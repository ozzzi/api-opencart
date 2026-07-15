<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Exceptions\Chat\CircuitBreakerOpenException;
use App\Exceptions\Chat\LlmUnavailableException;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Contracts\RateLimiterInterface;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\DTO\RateLimitResult;
use App\Settings\BotChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

final class MessageControllerDegradedTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $llmClient;

    private MockInterface $conversationService;

    private MockInterface $toolRegistry;

    private MockInterface $costTracker;

    private MockInterface $shopAssistant;

    private MockInterface $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $settings->sessionTtlMinutes = 60;
        $settings->degradedModeMessage = 'Ассистент временно недоступен.';
        $this->app->instance(BotChatSettings::class, $settings);

        $this->llmClient = Mockery::mock(LlmClientInterface::class);
        $this->conversationService = Mockery::mock(ConversationServiceInterface::class);
        $this->toolRegistry = Mockery::mock(ToolRegistryInterface::class);
        $this->costTracker = Mockery::mock(CostTrackerInterface::class);
        $this->shopAssistant = Mockery::mock(ShopAssistantInterface::class);
        $this->rateLimiter = Mockery::mock(RateLimiterInterface::class);

        $this->app->instance(LlmClientInterface::class, $this->llmClient);
        $this->app->instance(ConversationServiceInterface::class, $this->conversationService);
        $this->app->instance(ToolRegistryInterface::class, $this->toolRegistry);
        $this->app->instance(CostTrackerInterface::class, $this->costTracker);
        $this->app->instance(ShopAssistantInterface::class, $this->shopAssistant);
        $this->app->instance(RateLimiterInterface::class, $this->rateLimiter);
    }

    // ── rate limit ────────────────────────────────────────────────────────────

    public function test_rate_limit_response_always_includes_reason(): void
    {
        config(['app.debug' => false]);

        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::denied('session', 30));

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('event: limited', $content);
        $this->assertStringContainsString('"reason":"rate_limited_session"', $content);
        $this->assertStringContainsString('"retry_after":30', $content);
        $this->assertStringNotContainsString('"debug"', $content);
    }

    public function test_rate_limit_response_includes_debug_block_when_app_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::denied('ip', 45));

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('"reason":"rate_limited_ip"', $content);
        $this->assertStringContainsString('"debug":{"limit_type":"ip","retry_after_seconds":45}', $content);
    }

    // ── daily budget ──────────────────────────────────────────────────────────

    public function test_budget_exceeded_response_always_includes_reason(): void
    {
        config(['app.debug' => false]);

        $this->costTracker->allows('checkBudget')->andReturn(false);
        $this->costTracker->allows('getDailyCost')->andReturn(12.34);
        $this->costTracker->allows('getBudgetCapUsd')->andReturn(10.0);

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('event: degraded', $content);
        $this->assertStringContainsString('"reason":"daily_budget_exceeded"', $content);
        $this->assertStringContainsString('"lead_suggested":true', $content);
        $this->assertStringNotContainsString('"debug"', $content);
    }

    public function test_budget_exceeded_response_includes_debug_block_when_app_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $this->costTracker->allows('checkBudget')->andReturn(false);
        $this->costTracker->allows('getDailyCost')->andReturn(12.34);
        $this->costTracker->allows('getBudgetCapUsd')->andReturn(10.0);

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('"reason":"daily_budget_exceeded"', $content);
        $this->assertStringContainsString('"current_spend_usd":12.34', $content);
        $this->assertStringContainsString('"daily_budget_usd":10', $content);
    }

    // ── llm unavailable ───────────────────────────────────────────────────────

    public function test_llm_unavailable_response_always_includes_reason(): void
    {
        config(['app.debug' => false]);

        $this->allowLlmUnavailablePassThrough();

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('event: degraded', $content);
        $this->assertStringContainsString('"reason":"llm_unavailable"', $content);
        $this->assertStringContainsString('"lead_suggested":true', $content);
        $this->assertStringNotContainsString('"debug"', $content);
    }

    public function test_llm_unavailable_response_includes_debug_attempts_when_app_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $this->allowLlmUnavailablePassThrough();

        $content = $this->sendMessage()->streamedContent();

        $this->assertStringContainsString('"reason":"llm_unavailable"', $content);
        $this->assertStringContainsString('"model":"gpt-4o"', $content);
        $this->assertStringContainsString('"circuit_breaker_open":true', $content);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function sendMessage(): TestResponse
    {
        $session = ChatSession::factory()->create(['last_activity_at' => now()]);

        return $this->postJson(route('chat.message'), ['message' => 'Привет'], [
            'X-Chat-Session' => $session->id,
        ]);
    }

    private function allowLlmUnavailablePassThrough(): void
    {
        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());
        $this->shopAssistant->allows('detectLanguage')->andReturn('ru');
        $this->shopAssistant->allows('buildSystemPrompt')->andReturn('System prompt.');
        $this->conversationService->allows('addMessage')->andReturn($this->stubMessage());
        $this->conversationService->allows('buildContextWindow')->andReturn([]);
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);
        $this->llmClient->allows('getModel')->andReturn('gpt-4o-mini');

        $causes = [new CircuitBreakerOpenException('gpt-4o', 30)];
        $attempts = [[
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'error' => 'Circuit breaker open for model [gpt-4o]. Retry after 30s.',
            'circuit_breaker_open' => true,
        ]];

        $this->llmClient->allows('complete')->andThrow(new LlmUnavailableException($causes, $attempts));
    }

    private function stubMessage(int $id = 1): ChatMessage
    {
        $msg = new ChatMessage;
        $msg->id = $id;

        return $msg;
    }
}
