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
use App\Services\Chat\DTO\Blocks\ProductsBlock;
use App\Services\Chat\LlmOrchestrator;
use App\Services\Chat\Presentation\BlockCollector;
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

    /** Real collector, not a mock — it is a plain object with no dependencies. */
    private BlockCollector $blockCollector;

    private BotLlmSettings $llmSettings;

    private ?string $persistedContent = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $persistedParts = null;

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
        $this->blockCollector = new BlockCollector;

        $this->llmSettings = (new ReflectionClass(BotLlmSettings::class))->newInstanceWithoutConstructor();
        $this->llmSettings->primaryModel = 'gpt-4o-mini';
        $this->llmSettings->maxContextTokens = 2048;
    }

    // ── budget ────────────────────────────────────────────────────────────────

    public function test_throws_when_daily_budget_exceeded(): void
    {
        $this->costTracker->expects('checkBudget')->andReturn(false);
        $this->costTracker->allows('getDailyCost')->andReturn(12.34);
        $this->costTracker->allows('getBudgetCapUsd')->andReturn(10.0);

        $this->expectException(DailyBudgetExceededException::class);

        $this->make()->processMessage($this->makeSession(), 'Hi');
    }

    public function test_daily_budget_exceeded_exception_carries_spend_and_cap(): void
    {
        $this->costTracker->expects('checkBudget')->andReturn(false);
        $this->costTracker->allows('getDailyCost')->andReturn(12.34);
        $this->costTracker->allows('getBudgetCapUsd')->andReturn(10.0);

        try {
            $this->make()->processMessage($this->makeSession(), 'Hi');
            $this->fail('Expected DailyBudgetExceededException');
        } catch (DailyBudgetExceededException $e) {
            $this->assertSame(12.34, $e->currentSpendUsd);
            $this->assertSame(10.0, $e->dailyBudgetUsd);
        }
    }

    public function test_daily_budget_exceeded_exception_carries_spend_and_cap_when_streaming(): void
    {
        $this->costTracker->expects('checkBudget')->andReturn(false);
        $this->costTracker->allows('getDailyCost')->andReturn(12.34);
        $this->costTracker->allows('getBudgetCapUsd')->andReturn(10.0);

        try {
            /** @var Generator $generator */
            $generator = $this->make()->processMessage($this->makeSession(), 'Hi', stream: true);
            iterator_to_array($generator);
            $this->fail('Expected DailyBudgetExceededException');
        } catch (DailyBudgetExceededException $e) {
            $this->assertSame(12.34, $e->currentSpendUsd);
            $this->assertSame(10.0, $e->dailyBudgetUsd);
        }
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

    public function test_tool_result_message_is_persisted_with_tool_call_id(): void
    {
        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());
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

        $this->llmClient->expects('complete')->twice()->andReturn($toolCallResponse, $finalResponse);
        $this->toolRegistry->allows('execute')->andReturn('{"products": []}');

        $this->conversationService->expects('addMessage')
            ->with(Mockery::any(), 'tool', '{"products": []}', ['tool_name' => 'search_products', 'tool_call_id' => 'tc-1'])
            ->once()
            ->andReturn($this->stubMessage());

        // Other addMessage calls in this flow (user message, tool-call announcement, final assistant reply).
        $this->conversationService->allows('addMessage')->andReturn($this->stubMessage());

        $this->make()->processMessage($this->makeSession(), 'Show me laptops');
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

        $this->assertCount(4, $chunks);
        $this->assertSame(StreamChunkType::Start, $chunks[0]->type);
        $this->assertSame(StreamChunkType::Heartbeat, $chunks[1]->type);
        $this->assertSame(StreamChunkType::Text, $chunks[2]->type);
        $this->assertSame('Streaming answer', $chunks[2]->content);
        $this->assertSame(StreamChunkType::Done, $chunks[3]->type);
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

    public function test_start_chunk_carries_the_persisted_user_message_id(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse());

        $this->conversationService->shouldReceive('addMessage')
            ->andReturnUsing(fn (...$args): ChatMessage => $this->stubMessage(
                ($args[1] ?? null) === 'user' ? 777 : 1,
            ));

        $chunks = iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'Hi', stream: true),
            false,
        );

        $this->assertSame(StreamChunkType::Start, $chunks[0]->type);
        $this->assertSame(777, $chunks[0]->messageId);
    }

    // ── structured parts ──────────────────────────────────────────────────────

    public function test_interim_content_alongside_tool_calls_is_yielded_as_text(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse('Дивлюся, що є у вашому бюджеті…'),
            $this->makeResponse('Обидва тягнуть навчальні задачі.'),
        );
        $this->toolRegistry->allows('execute')->andReturn('{"shown":true}');

        $chunks = iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'Ноутбук до 30000', stream: true),
            false,
        );

        $texts = array_values(array_map(
            static fn ($chunk) => $chunk->content,
            array_filter($chunks, static fn ($chunk) => $chunk->type === StreamChunkType::Text),
        ));

        $this->assertSame(
            ['Дивлюся, що є у вашому бюджеті…', 'Обидва тягнуть навчальні задачі.'],
            $texts,
        );
    }

    public function test_block_chunk_is_yielded_between_tool_running_and_tool_done(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse(),
            $this->makeResponse(),
        );
        $this->pushBlockOnToolExecution();

        $chunks = iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'Hi', stream: true),
            false,
        );

        $types = array_map(static fn ($chunk) => $chunk->type, $chunks);

        $running = array_search(StreamChunkType::ToolRunning, $types, strict: true);
        $block = array_search(StreamChunkType::Block, $types, strict: true);
        $done = array_search(StreamChunkType::ToolDone, $types, strict: true);

        $this->assertIsInt($block, 'expected a Block chunk in the stream');
        $this->assertGreaterThan($running, $block);
        $this->assertLessThan($done, $block);
    }

    public function test_parts_are_persisted_in_stream_order(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse('Ось варіанти:'),
            $this->makeResponse('Перший помітно легший.'),
        );
        $this->pushBlockOnToolExecution();

        $this->capturePersistedAssistantMessage();

        iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'Hi', stream: true),
            false,
        );

        $this->assertSame(
            ['text', 'products', 'text'],
            array_column($this->persistedParts, 'type'),
        );
        $this->assertSame('Ось варіанти:', $this->persistedParts[0]['text']);
        $this->assertSame('Перший помітно легший.', $this->persistedParts[2]['text']);
    }

    public function test_persisted_content_holds_text_parts_only(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse('Ось варіанти:'),
            $this->makeResponse('Перший помітно легший.'),
        );
        $this->pushBlockOnToolExecution();

        $this->capturePersistedAssistantMessage();

        iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'Hi', stream: true),
            false,
        );

        $this->assertSame("Ось варіанти:\n\nПерший помітно легший.", $this->persistedContent);
    }

    public function test_non_streaming_path_persists_parts_too(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse(),
            $this->makeResponse('Готово.'),
        );
        $this->pushBlockOnToolExecution();

        $this->capturePersistedAssistantMessage();

        $this->make()->processMessage($this->makeSession(), 'Hi');

        $this->assertSame(['products', 'text'], array_column($this->persistedParts, 'type'));
    }

    public function test_blocks_left_over_from_a_previous_run_are_discarded(): void
    {
        $this->allowPassThrough();
        $this->llmClient->allows('complete')->andReturn($this->makeResponse('Готово.'));

        // Residue from a run that threw mid-loop.
        $this->blockCollector->push(new ProductsBlock([]));

        $this->capturePersistedAssistantMessage();

        $this->make()->processMessage($this->makeSession(), 'Hi');

        $this->assertSame(['text'], array_column($this->persistedParts, 'type'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Records the final assistant addMessage() call — the only one carrying a
     * `parts` option — into $persistedContent / $persistedParts.
     */
    private function capturePersistedAssistantMessage(): void
    {
        $this->conversationService->shouldReceive('addMessage')
            ->andReturnUsing(function (...$args): ChatMessage {
                if (($args[1] ?? null) === 'assistant' && isset($args[3]['parts'])) {
                    $this->persistedContent = $args[2];
                    $this->persistedParts = $args[3]['parts'];
                }

                return $this->stubMessage();
            });
    }

    /** Makes the tool registry behave like a presentational tool. */
    private function pushBlockOnToolExecution(): void
    {
        $this->toolRegistry->allows('execute')->andReturnUsing(function (): string {
            $this->blockCollector->push(new ProductsBlock([]));

            return '{"shown":true}';
        });
    }

    private function makeToolCallResponse(?string $content = null): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            toolCalls: [new ToolCall('tc-1', 'show_products', ['product_ids' => [42]])],
            finishReason: 'tool_calls',
            usage: new UsageStats(10, 5, 0.0),
        );
    }


    private function make(): LlmOrchestrator
    {
        return new LlmOrchestrator(
            llmClient: $this->llmClient,
            conversationService: $this->conversationService,
            toolRegistry: $this->toolRegistry,
            costTracker: $this->costTracker,
            shopAssistant: $this->shopAssistant,
            rateLimiter: $this->rateLimiter,
            blockCollector: $this->blockCollector,
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
        $this->shopAssistant->allows('buildSystemPrompt')->andReturn('You are a bot.');
        $this->conversationService->shouldReceive('addMessage')->andReturn($this->stubMessage())->byDefault();
        $this->conversationService->allows('buildContextWindow')->andReturn([]);
        $this->conversationService->shouldReceive('needsSummarization')->andReturn(false)->byDefault();
        $this->costTracker->shouldReceive('log')->byDefault();
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);
    }
}
