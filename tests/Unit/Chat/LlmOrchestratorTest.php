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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;
use Generator;

final class LlmOrchestratorTest extends TestCase
{
    /**
     * A search_products result the model could have put on a card — what arms the guard.
     */
    private const string SEARCH_OK_RESULT =
        '{"status":"ok","results":[{"product_id":60,"name":"Kobra","price":65}],"found":true}';
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

    // ── cost accounting ───────────────────────────────────────────────────────

    /**
     * FR-8.1 wants a row per call. Logging only the answering one hid every tool round
     * from llm_api_calls — and from the budget guard, which reads that spend back.
     */
    public function test_every_model_call_in_a_tool_loop_is_logged(): void
    {
        $this->allowPassThrough();
        $this->toolRegistry->allows('execute')->andReturn('{"shown":true}');

        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse(),
            $this->makeToolCallResponse(),
            $this->makeResponse('Готово.'),
        );

        $this->costTracker->expects('log')->times(3);

        $this->make()->processMessage($this->makeSession(), 'Hi');
    }

    public function test_a_tool_round_is_logged_against_the_message_it_produced(): void
    {
        $this->allowPassThrough();
        $this->toolRegistry->allows('execute')->andReturn('{"shown":true}');

        $this->conversationService->shouldReceive('addMessage')
            ->andReturnUsing(fn (...$args): ChatMessage => $this->stubMessage(
                isset($args[3]['tool_calls']) ? 555 : 1,
            ));

        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse(),
            $this->makeResponse('Готово.'),
        );

        $messageIds = [];
        $this->costTracker->allows('log')->andReturnUsing(
            function (...$args) use (&$messageIds): void {
                $messageIds[] = $args[1];
            },
        );

        $this->make()->processMessage($this->makeSession(), 'Hi');

        // The tool round points at its own assistant message, the answer at the reply.
        $this->assertSame([555, 1], $messageIds);
    }

    public function test_an_answer_discarded_by_the_repair_pass_is_still_billed(): void
    {
        $this->allowPassThrough();
        $this->routeToolExecution();

        $this->llmClient->allows('complete')->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн.'),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння.'),
        );

        // Search round, the discarded answer, the show_products round, the real answer.
        $this->costTracker->expects('log')->times(4);

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');
    }

    public function test_the_discarded_answer_is_logged_without_a_message_id(): void
    {
        $this->allowPassThrough();
        $this->routeToolExecution();

        $this->llmClient->allows('complete')->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн.'),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння.'),
        );

        $messageIds = [];
        $this->costTracker->allows('log')->andReturnUsing(
            function (...$args) use (&$messageIds): void {
                $messageIds[] = $args[1];
            },
        );

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');

        // It is the one call in a turn that never becomes a row in chat_messages.
        $this->assertNull($messageIds[1]);
    }

    public function test_tokens_used_on_the_reply_covers_the_whole_turn(): void
    {
        $this->allowPassThrough();
        $this->toolRegistry->allows('execute')->andReturn('{"shown":true}');

        // makeToolCallResponse bills 10 + 5, makeResponse bills 50 + 10.
        $this->llmClient->allows('complete')->andReturn(
            $this->makeToolCallResponse(),
            $this->makeResponse('Готово.'),
        );

        $tokens = null;
        $this->conversationService->shouldReceive('addMessage')
            ->andReturnUsing(function (...$args) use (&$tokens): ChatMessage {
                if (isset($args[3]['parts'])) {
                    $tokens = $args[3]['tokens_used'];
                }

                return $this->stubMessage();
            });

        $this->make()->processMessage($this->makeSession(), 'Hi');

        $this->assertSame(75, $tokens);
    }

    // ── products block guard ──────────────────────────────────────────────────

    /**
     * The invariant the guard defends: a search that found products must end in cards
     * built from live data, never in prose the model composed from the search hits.
     */
    public function test_repair_pass_recovers_a_products_block_the_model_skipped(): void
    {
        $this->allowPassThrough();
        $this->capturePersistedAssistantMessage();
        $this->routeToolExecution();

        $this->llmClient->expects('complete')->times(4)->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн: http://shop.test/kobra'),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння, підійде для щоденного носіння.'),
        );

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');

        $this->assertSame(['products', 'text'], array_column($this->persistedParts, 'type'));
    }

    public function test_repair_pass_discards_the_prose_that_restated_the_facts(): void
    {
        $this->allowPassThrough();
        $this->capturePersistedAssistantMessage();
        $this->routeToolExecution();

        $this->llmClient->allows('complete')->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн: http://shop.test/kobra'),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння, підійде для щоденного носіння.'),
        );

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');

        $this->assertSame('Класичне плетіння, підійде для щоденного носіння.', $this->persistedContent);
    }

    public function test_repair_pass_streams_the_recovered_block(): void
    {
        $this->allowPassThrough();
        $this->routeToolExecution();

        $this->llmClient->allows('complete')->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн.'),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння.'),
        );

        $chunks = iterator_to_array(
            $this->make()->processMessage($this->makeSession(), 'плетення кобра', stream: true),
            false,
        );

        $types = array_map(static fn ($chunk) => $chunk->type, $chunks);

        $this->assertContains(StreamChunkType::Block, $types);
        $this->assertLessThan(
            array_search(StreamChunkType::Done, $types, true),
            array_search(StreamChunkType::Block, $types, true),
        );
    }

    public function test_no_repair_when_the_model_showed_the_card_itself(): void
    {
        $this->allowPassThrough();
        $this->routeToolExecution();

        // Search, show_products, final reply — and nothing more.
        $this->llmClient->expects('complete')->times(3)->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeToolCallResponse(),
            $this->makeResponse('Класичне плетіння.'),
        );

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');
    }

    public function test_no_repair_when_the_search_asked_for_clarification(): void
    {
        $this->allowPassThrough();
        $this->toolRegistry->allows('execute')->andReturn(
            '{"status":"need_clarification","reason":"broad_query","total_hits":16,"products":[]}',
        );

        $this->llmClient->expects('complete')->twice()->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Уточніть, будь ласка, який бюджет?'),
        );

        $this->make()->processMessage($this->makeSession(), 'браслет кобра');
    }

    public function test_no_repair_when_the_search_found_nothing(): void
    {
        $this->allowPassThrough();
        $this->toolRegistry->allows('execute')->andReturn('{"status":"empty","results":[],"found":false}');

        $this->llmClient->expects('complete')->twice()->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('На жаль, нічого не знайшлося.'),
        );

        $this->make()->processMessage($this->makeSession(), 'браслет кобра');
    }

    public function test_reply_survives_a_model_that_ignores_the_repair(): void
    {
        $this->allowPassThrough();
        $this->capturePersistedAssistantMessage();
        $this->routeToolExecution(showEmitsBlock: false);

        Log::shouldReceive('channel')->with('chat')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'showed no card'));

        $this->llmClient->allows('complete')->andReturn(
            $this->makeSearchCallResponse(),
            $this->makeResponse('Ось Кобра за 65 грн.'),
            $this->makeResponse('Ось Кобра за 65 грн.'),
        );

        $this->make()->processMessage($this->makeSession(), 'плетення кобра');

        $this->assertSame(['text'], array_column($this->persistedParts, 'type'));
        $this->assertSame('Ось Кобра за 65 грн.', $this->persistedContent);
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

    private function makeSearchCallResponse(): LlmResponse
    {
        return new LlmResponse(
            content: null,
            toolCalls: [new ToolCall('tc-search', 'search_products', ['query' => 'браслет кобра'])],
            finishReason: 'tool_calls',
            usage: new UsageStats(10, 5, 0.0),
        );
    }

    /** search_products answers with hits; show_products emits the card, unless told not to. */
    private function routeToolExecution(bool $showEmitsBlock = true): void
    {
        $this->toolRegistry->allows('execute')->andReturnUsing(
            function (string $name) use ($showEmitsBlock): string {
                if ($name === 'search_products') {
                    return self::SEARCH_OK_RESULT;
                }

                if ($showEmitsBlock) {
                    $this->blockCollector->push(new ProductsBlock([]));
                }

                return '{"shown":true}';
            },
        );
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
