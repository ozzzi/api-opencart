<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\Contracts\RateLimiterInterface;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Services\Chat\Contracts\ToolRegistryInterface;
use App\Services\Chat\DTO\Blocks\ProductCard;
use App\Services\Chat\DTO\Blocks\ProductPrice;
use App\Services\Chat\DTO\Blocks\ProductsBlock;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\RateLimitResult;
use App\Services\Chat\DTO\ToolCall;
use App\Services\Chat\DTO\UsageStats;
use App\Services\Chat\Presentation\BlockCollector;
use App\Settings\BotChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

/**
 * The regression that motivated the guard: after a clarification round the model
 * answered a successful search with a prose list and never called show_products, so
 * the widget received no card to render.
 *
 * Covered end to end because the fix spans the tool loop and the SSE mapping — the
 * recovered block has to reach the wire as its own event, ahead of `done`.
 */
final class MessageControllerProductBlockGuardTest extends TestCase
{
    use RefreshDatabase;

    private const string PROSE_WITH_FACTS = 'Ось Браслет "Кобра" за 65 грн: https://shop.test/kobra';

    private const string REASONING_PROSE = 'Класичне плетіння, підійде для щоденного носіння.';

    private MockInterface $llmClient;

    private MockInterface $toolRegistry;

    private BlockCollector $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $settings->sessionTtlMinutes = 60;
        $settings->degradedModeMessage = 'Помічник тимчасово недоступний.';
        $this->app->instance(BotChatSettings::class, $settings);

        $this->llmClient = Mockery::mock(LlmClientInterface::class);
        $this->llmClient->allows('getModel')->andReturn('gpt-4o-mini');
        $this->llmClient->allows('getProvider')->andReturn('openai');

        $conversationService = Mockery::mock(ConversationServiceInterface::class);
        $conversationService->allows('buildContextWindow')->andReturn([]);
        $conversationService->allows('needsSummarization')->andReturn(false);
        $conversationService->allows('addMessage')->andReturn($this->stubMessage());

        $costTracker = Mockery::mock(CostTrackerInterface::class);
        $costTracker->allows('checkBudget')->andReturn(true);
        $costTracker->allows('log');

        $rateLimiter = Mockery::mock(RateLimiterInterface::class);
        $rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());

        $shopAssistant = Mockery::mock(ShopAssistantInterface::class);
        $shopAssistant->allows('buildSystemPrompt')->andReturn('You are a bot.');

        $this->toolRegistry = Mockery::mock(ToolRegistryInterface::class);
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);

        $this->blocks = new BlockCollector;

        $this->app->instance(LlmClientInterface::class, $this->llmClient);
        $this->app->instance(ConversationServiceInterface::class, $conversationService);
        $this->app->instance(ToolRegistryInterface::class, $this->toolRegistry);
        $this->app->instance(CostTrackerInterface::class, $costTracker);
        $this->app->instance(ShopAssistantInterface::class, $shopAssistant);
        $this->app->instance(RateLimiterInterface::class, $rateLimiter);
        $this->app->instance(BlockCollector::class, $this->blocks);

        $this->toolRegistry->allows('execute')->andReturnUsing(
            function (string $name): string {
                if ($name === 'search_products') {
                    return '{"status":"ok","results":[{"product_id":60,"name":"Kobra","price":65}],"found":true}';
                }

                $this->blocks->push($this->makeProductsBlock());

                return '{"shown":true}';
            },
        );

        // Search, a prose answer that skips the card, then the repaired turn.
        $this->llmClient->allows('complete')->andReturn(
            $this->toolCallResponse('tc-search', 'search_products', ['query' => 'браслет плетення кобра']),
            $this->stopResponse(self::PROSE_WITH_FACTS),
            $this->toolCallResponse('tc-show', 'show_products', ['product_ids' => [60]]),
            $this->stopResponse(self::REASONING_PROSE),
        );
    }

    public function test_a_skipped_card_is_recovered_as_a_block_event(): void
    {
        $content = $this->sendMessage();

        $this->assertStringContainsString('event: block', $content);
        $this->assertStringContainsString('"type":"products"', $content);
        $this->assertStringContainsString('"id":60', $content);
    }

    public function test_the_recovered_block_arrives_before_the_stream_closes(): void
    {
        $content = $this->sendMessage();

        $this->assertLessThan(
            mb_strpos($content, 'event: done'),
            mb_strpos($content, 'event: block'),
        );
    }

    public function test_the_prose_that_restated_the_facts_never_reaches_the_widget(): void
    {
        $content = $this->sendMessage();

        $this->assertStringNotContainsString('65 грн', $content);
        $this->assertStringContainsString(self::REASONING_PROSE, $content);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function sendMessage(): string
    {
        $session = ChatSession::factory()->create(['last_activity_at' => now()]);

        return $this->postJson(
            route('chat.message'),
            ['message' => 'плетение кобра'],
            ['X-Chat-Session' => $session->id],
        )->streamedContent();
    }

    /** @param array<string, mixed> $arguments */
    private function toolCallResponse(string $id, string $name, array $arguments): LlmResponse
    {
        return new LlmResponse(
            content: null,
            toolCalls: [new ToolCall($id, $name, $arguments)],
            finishReason: 'tool_calls',
            usage: new UsageStats(10, 5, 0.0),
        );
    }

    private function stopResponse(string $content): LlmResponse
    {
        return new LlmResponse(
            content: $content,
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(50, 10, 0.001),
        );
    }

    private function makeProductsBlock(): ProductsBlock
    {
        return new ProductsBlock([
            new ProductCard(
                id: 60,
                name: 'Браслет "Кобра"',
                url: 'https://shop.test/kobra',
                image: null,
                price: new ProductPrice('65 ₴', 65.0, null, null, 'UAH'),
                inStock: true,
                availability: 'В наявності',
                attributes: [],
            ),
        ]);
    }

    private function stubMessage(int $id = 1): ChatMessage
    {
        $message = new ChatMessage;
        $message->id = $id;

        return $message;
    }
}
