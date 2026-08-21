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
use App\Services\Chat\DTO\Blocks\ProductAttribute;
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
 * Covers the SSE side of structured output: that a presentational tool's block
 * reaches the widget as its own `block` event, in the right place in the stream.
 */
final class MessageControllerBlockEventTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $llmClient;

    private MockInterface $conversationService;

    private MockInterface $toolRegistry;

    private MockInterface $costTracker;

    private MockInterface $shopAssistant;

    private MockInterface $rateLimiter;

    private BlockCollector $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $settings->sessionTtlMinutes = 60;
        $settings->degradedModeMessage = 'Ассистент временно недоступен.';
        $this->app->instance(BotChatSettings::class, $settings);

        $this->llmClient = Mockery::mock(LlmClientInterface::class);
        $this->llmClient->allows('getModel')->andReturn('gpt-4o-mini');
        $this->llmClient->allows('getProvider')->andReturn('openai');

        $this->conversationService = Mockery::mock(ConversationServiceInterface::class);
        $this->toolRegistry = Mockery::mock(ToolRegistryInterface::class);
        $this->costTracker = Mockery::mock(CostTrackerInterface::class);
        $this->shopAssistant = Mockery::mock(ShopAssistantInterface::class);
        $this->rateLimiter = Mockery::mock(RateLimiterInterface::class);
        $this->blocks = new BlockCollector;

        $this->app->instance(LlmClientInterface::class, $this->llmClient);
        $this->app->instance(ConversationServiceInterface::class, $this->conversationService);
        $this->app->instance(ToolRegistryInterface::class, $this->toolRegistry);
        $this->app->instance(CostTrackerInterface::class, $this->costTracker);
        $this->app->instance(ShopAssistantInterface::class, $this->shopAssistant);
        $this->app->instance(RateLimiterInterface::class, $this->rateLimiter);
        $this->app->instance(BlockCollector::class, $this->blocks);

        $this->costTracker->allows('checkBudget')->andReturn(true);
        $this->costTracker->allows('log');
        $this->rateLimiter->allows('check')->andReturn(RateLimitResult::allowed());
        $this->shopAssistant->allows('buildSystemPrompt')->andReturn('You are a bot.');
        $this->conversationService->allows('buildContextWindow')->andReturn([]);
        $this->conversationService->allows('needsSummarization')->andReturn(false);
        $this->conversationService->allows('addMessage')->andReturn($this->stubMessage());
        $this->toolRegistry->allows('getOpenAiTools')->andReturn([]);

        $this->llmClient->allows('complete')->andReturn(
            new LlmResponse(
                content: 'Дивлюся, що є у вашому бюджеті…',
                toolCalls: [new ToolCall('tc-1', 'show_products', ['product_ids' => [42]])],
                finishReason: 'tool_calls',
                usage: new UsageStats(10, 5, 0.0),
            ),
            new LlmResponse(
                content: 'Acer легший і дешевший.',
                toolCalls: [],
                finishReason: 'stop',
                usage: new UsageStats(50, 10, 0.001),
            ),
        );

        $this->toolRegistry->allows('execute')->andReturnUsing(function (): string {
            $this->blocks->push($this->makeProductsBlock());

            return '{"shown":true}';
        });
    }

    public function test_a_block_event_carries_the_product_payload(): void
    {
        $content = $this->sendMessage();

        $this->assertStringContainsString('event: block', $content);
        $this->assertStringContainsString('"type":"products"', $content);
        $this->assertStringContainsString('"id":42', $content);
        $this->assertStringContainsString('"current":"28 990 ₴"', $content);
    }

    public function test_urls_are_not_escaped_into_unusable_strings(): void
    {
        $content = $this->sendMessage();

        $this->assertStringContainsString('https://shop.test/acer-aspire-5', $content);
        $this->assertStringNotContainsString('https:\/\/', $content);
    }

    public function test_the_block_lands_inside_its_tool_activity_window(): void
    {
        $content = $this->sendMessage();

        $running = mb_strpos($content, '"status":"running"');
        $block = mb_strpos($content, 'event: block');
        $done = mb_strpos($content, '"status":"done"');

        $this->assertIsInt($block);
        $this->assertGreaterThan($running, $block);
        $this->assertLessThan($done, $block);
    }

    public function test_prose_written_before_the_tool_call_is_streamed_before_the_block(): void
    {
        $content = $this->sendMessage();

        $this->assertLessThan(
            mb_strpos($content, 'event: block'),
            mb_strpos($content, 'Дивлюся, що є у вашому бюджеті'),
        );
        $this->assertGreaterThan(
            mb_strpos($content, 'event: block'),
            mb_strpos($content, 'Acer легший і дешевший.'),
        );
    }

    public function test_the_start_event_exposes_the_user_message_id(): void
    {
        $this->assertStringContainsString('"user_message_id":1', $this->sendMessage());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function sendMessage(): string
    {
        $session = ChatSession::factory()->create(['last_activity_at' => now()]);

        return $this->postJson(
            route('chat.message'),
            ['message' => 'Потрібен ноутбук до 30000'],
            ['X-Chat-Session' => $session->id],
        )->streamedContent();
    }

    private function makeProductsBlock(): ProductsBlock
    {
        return new ProductsBlock([
            new ProductCard(
                id: 42,
                name: 'Acer Aspire 5',
                url: 'https://shop.test/acer-aspire-5',
                image: 'https://shop.test/image/catalog/acer.jpg',
                price: new ProductPrice('28 990 ₴', 28990.0, '31 990 ₴', 31990.0, 'UAH'),
                inStock: true,
                availability: 'В наявності',
                attributes: [new ProductAttribute('RAM', '16 ГБ')],
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
