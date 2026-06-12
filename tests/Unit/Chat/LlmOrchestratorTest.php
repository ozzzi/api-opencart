<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Exceptions\Chat\DailyBudgetExceededException;
use App\Exceptions\Chat\RateLimitExceededException;
use App\Jobs\SummarizeConversationJob;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Contracts\RateLimiterInterface;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\RateLimitResult;
use App\Services\Chat\DTO\StreamChunkType;
use App\Services\Chat\DTO\ToolCall;
use App\Services\Chat\DTO\UsageStats;
use App\Services\Chat\LlmOrchestrator;
use App\Settings\BotLlmSettings;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;
use Generator;

final class LlmOrchestratorTest extends TestCase
{
    private MockInterface $llmClient;
    private MockInterface $conversationService;
    private MockInterface $toolRegistry;
    private MockInterface $costTracker;
    private MockInterface $shopAssistant;
    private MockInterface $rateLimiter;

    private BotLlmSettings $llmSettings;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->llmClient = Mockery::mock(LlmClientInterface::class);
        $this->llmClient->allows('getModel')->andReturn('gpt-4o-mini');
        $this->llmClient->allows('getProvider')->andReturn('openai');

        $this->conversationService = Mockery::mock(ConversationServiceInterface::class);
        $this->toolRegistry = Mockery::mock(ToolRegistryInterface::class);
        $this->costTracker = Mockery::mock(CostTrackerInterface::class);
        $this->shopAssistant = Mockery::mock(ShopAssistantInterface::class);
        $this->rateLimiter = Mockery::mock(RateLimiterInterface::class);

        $this->llmSettings = (new ReflectionClass(BotLlmSettings::class))->newInstanceWithoutConstructor();
        $this->llmSettings->primaryModel = 'gpt-4o-mini';
        $this->llmSettings->maxContextTokens = 2048;
    }

    // ── budget ────────────────────────────────────────────────────────────────

    public function test_throws_when_daily_budget_exceeded(): void
    {
        $this->costTracker->expects('checkBudget')->andReturn(false);

        $this->expectException(DailyBudgetExceededException::class);

        $this->make()->processMessage($this->makeSession(), 'Hi');
    }

    // ── rate limit ────────────────────────────────────────────────────────────

    public function test_throws_when_rate_limit_exceeded(): void
    {
        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->expects('check')
            ->andReturn(RateLimitResult::denied('session', 30));

        $this->expectException(RateLimitExceededException::class);

        $this->make()->processMessage($this->makeSession(), 'Hi');
    }

    // ── single response (no tool calls) ───────────────────────────────────────

    public function test_returns_llm_response_on_single_complete_call(): void
    {
        $this->allowPassThrough();
        $response = $this->makeResponse('Hello from bot!');
        $this->llmClient->expects('complete')->once()->andReturn($response);

        $result = $this->make()->processMessage($this->makeSession(), 'Hi');

        $this->assertInstanceOf(LlmResponse::class, $result);
        $this->assertSame('Hello from bot!', $result->content);
    }

    public function test_user_message_is_persisted_before_llm_call(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());

        $this->conversationService->expects('addMessage')
            ->with(Mockery::any(), 'user', 'Hi there')
            ->once()
            ->andReturn($this->stubMessage());

        $this->make()->processMessage($this->makeSession(), 'Hi there');
    }

    // ── tool-call loop ────────────────────────────────────────────────────────

    public function test_tool_loop_with_two_iterations(): void
    {
        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());
        $this->shopAssistant->allows('detectLanguage')->andReturn('ru');
        $this->shopAssistant->allows('buildSystemPrompt')->andReturn('System.');
        $this->conversationService->allows('buildContextWindow')->andReturn([]);
        $this->conversationService->allows('needsSummarization')->andReturn(false);
        $this->costTracker->allows('log');
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);

        $toolCallResponse = new LlmResponse(
            content: null,
            toolCalls: [new ToolCall('tc-1', 'search_products', ['query' => 'laptop'])],
            finishReason: 'tool_calls',
            usage: new UsageStats(10, 5, 0.0),
        );
        $finalResponse = $this->makeResponse('Here are some laptops.');

        // Two LLM calls: one tool call + one final response
        $this->llmClient->expects('complete')->twice()->andReturn($toolCallResponse, $finalResponse);

        // Tool execution
        $this->toolRegistry->expects('execute')
            ->with('search_products', ['query' => 'laptop'], Mockery::any())
            ->once()
            ->andReturn('{"products": []}');

        // Four addMessage calls: user, assistant-tool-call, tool-result, final-assistant
        $this->conversationService->expects('addMessage')->times(4)->andReturn($this->stubMessage());

        $result = $this->make()->processMessage($this->makeSession(), 'Show me laptops');

        $this->assertSame('Here are some laptops.', $result->content);
    }

    // ── language detection ────────────────────────────────────────────────────

    public function test_session_language_is_updated_when_detected_language_differs(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());
        $this->shopAssistant->allows('detectLanguage')->andReturn('uk');

        $session = $this->makeSession(); // language = 'ru'

        // Session needs save() — mock it by using a real ChatSession (no DB)
        // We check the language was changed
        $this->make()->processMessage($session, 'Привіт');

        $this->assertSame('uk', $session->language);
    }

    // ── summarization ─────────────────────────────────────────────────────────

    public function test_summarize_job_is_dispatched_when_needed(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());
        $this->conversationService->allows('needsSummarization')->andReturn(true);

        $this->make()->processMessage($this->makeSession(), 'Hi');

        Queue::assertPushed(SummarizeConversationJob::class);
    }

    public function test_summarize_job_is_not_dispatched_when_not_needed(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());

        $this->make()->processMessage($this->makeSession(), 'Hi');

        Queue::assertNothingPushed();
    }

    // ── streaming ─────────────────────────────────────────────────────────────

    public function test_returns_generator_when_stream_is_true(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse('Streaming answer'));

        $result = $this->make()->processMessage($this->makeSession(), 'Hi', stream: true);

        $this->assertInstanceOf(Generator::class, $result);
    }

    public function test_generator_yields_text_chunk_then_done(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse('Streaming answer'));

        $generator = $this->make()->processMessage($this->makeSession(), 'Hi', stream: true);

        $chunks = iterator_to_array($generator, false);

        $this->assertCount(2, $chunks);
        $this->assertSame(StreamChunkType::Text, $chunks[0]->type);
        $this->assertSame('Streaming answer', $chunks[0]->content);
        $this->assertSame(StreamChunkType::Done, $chunks[1]->type);
    }

    public function test_cost_is_logged_after_successful_response(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());

        $this->costTracker->expects('log')
            ->with(
                'test-session-id',
                Mockery::any(),
                Mockery::any(),
                'gpt-4o-mini',
                'chat',
                'openai',
                Mockery::any(),
            )
            ->once();

        $this->make()->processMessage($this->makeSession(), 'Hi');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function make(): LlmOrchestrator
    {
        return new LlmOrchestrator(
            llmClient: $this->llmClient,
            conversationService: $this->conversationService,
            toolRegistry: $this->toolRegistry,
            costTracker: $this->costTracker,
            shopAssistant: $this->shopAssistant,
            rateLimiter: $this->rateLimiter,
            llmSettings: $this->llmSettings,
        );
    }

    private function makeSession(): ChatSession
    {
        $session = new ChatSession;
        $session->id = 'test-session-id';
        $session->language = 'ru';
        $session->ip_address = '127.0.0.1';

        return $session;
    }

    private function makeResponse(string $content = 'Hello!', string $finishReason = 'stop'): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            toolCalls: [],
            finishReason: $finishReason,
            usage: new UsageStats(promptTokens: 50, completionTokens: 10, costUsd: 0.001),
        );
    }

    private function stubMessage(int $id = 1): ChatMessage
    {
        $msg = new ChatMessage;
        $msg->id = $id;

        return $msg;
    }

    private function allowPassThrough(): void
    {
        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());
        $this->shopAssistant->shouldReceive('detectLanguage')->andReturn('ru')->byDefault();
        $this->shopAssistant->allows('buildSystemPrompt')->andReturn('You are a bot.');
        $this->conversationService->shouldReceive('addMessage')->andReturn($this->stubMessage())->byDefault();
        $this->conversationService->allows('buildContextWindow')->andReturn([]);
        $this->conversationService->shouldReceive('needsSummarization')->andReturn(false)->byDefault();
        $this->costTracker->shouldReceive('log')->byDefault();
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);
    }
}
