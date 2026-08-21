<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Exceptions\Chat\SessionNotFoundException;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\ConversationService;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\ToolCall;
use App\Settings\BotChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

final class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BotChatSettings $settings;

    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $this->settings->sessionTtlMinutes = 60;
        $this->settings->contextWindowSize = 5;
        $this->settings->summaryThreshold = 10;

        $this->service = new ConversationService($this->settings);
    }

    // ── createSession ─────────────────────────────────────────────────────────

    public function test_create_session_persists_and_returns_chat_session(): void
    {
        $session = $this->service->createSession('127.0.0.1', 'Mozilla/5.0', 'uk');

        $this->assertInstanceOf(ChatSession::class, $session);
        $this->assertDatabaseHas('chat_sessions', [
            'id' => $session->id,
            'ip_address' => '127.0.0.1',
            'language' => 'uk',
        ]);
    }

    public function test_create_session_assigns_uuid(): void
    {
        $session = $this->service->createSession('10.0.0.1', 'Agent/1.0', 'ru');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $session->id,
        );
    }

    // ── getSession ────────────────────────────────────────────────────────────

    public function test_get_session_returns_active_session(): void
    {
        $created = ChatSession::factory()->create([
            'last_activity_at' => now()->subMinutes(30),
        ]);

        $session = $this->service->getSession($created->id);

        $this->assertEquals($created->id, $session->id);
    }

    public function test_get_session_throws_when_not_found(): void
    {
        $this->expectException(SessionNotFoundException::class);

        $this->service->getSession('00000000-0000-0000-0000-000000000000');
    }

    public function test_get_session_throws_when_ttl_expired(): void
    {
        $session = ChatSession::factory()->create([
            'last_activity_at' => now()->subMinutes(61),
        ]);

        $this->expectException(SessionNotFoundException::class);

        $this->service->getSession($session->id);
    }

    // ── addMessage ────────────────────────────────────────────────────────────

    public function test_add_message_persists_and_returns_chat_message(): void
    {
        $session = ChatSession::factory()->create();

        $message = $this->service->addMessage($session, 'user', 'Hello!');

        $this->assertInstanceOf(ChatMessage::class, $message);
        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Hello!',
        ]);
    }

    public function test_add_message_updates_last_activity_at(): void
    {
        $session = ChatSession::factory()->create([
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $before = $session->last_activity_at->copy();

        $this->service->addMessage($session, 'user', 'ping');

        $this->assertTrue($session->last_activity_at->isAfter($before));
    }

    public function test_add_message_stores_optional_fields(): void
    {
        $session = ChatSession::factory()->create();

        $message = $this->service->addMessage($session, 'assistant', 'Hi!', [
            'model' => 'gpt-4o',
            'tokens_used' => 42,
            'latency_ms' => 300,
            'fallback_used' => true,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'model' => 'gpt-4o',
            'tokens_used' => 42,
            'latency_ms' => 300,
            'fallback_used' => 1,
        ]);
    }

    public function test_add_message_stores_tool_call_id(): void
    {
        $session = ChatSession::factory()->create();

        $message = $this->service->addMessage($session, 'tool', '{"result":true}', [
            'tool_name' => 'search_products',
            'tool_call_id' => 'call_abc123',
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'tool_name' => 'search_products',
            'tool_call_id' => 'call_abc123',
        ]);
    }

    public function test_add_message_round_trips_structured_parts(): void
    {
        $session = ChatSession::factory()->create();
        $parts = [
            ['type' => 'text', 'text' => 'Ось варіанти:'],
            ['type' => 'products', 'items' => [['id' => 42, 'name' => 'Acer Aspire 5']]],
        ];

        $message = $this->service->addMessage($session, 'assistant', 'Ось варіанти:', [
            'parts' => $parts,
        ]);

        $this->assertSame($parts, $message->fresh()->parts);
    }

    public function test_add_message_leaves_parts_null_when_not_supplied(): void
    {
        $session = ChatSession::factory()->create();

        $message = $this->service->addMessage($session, 'user', 'Привіт');

        $this->assertNull($message->fresh()->parts);
    }

    // ── buildContextWindow ────────────────────────────────────────────────────

    public function test_build_context_window_returns_llm_chat_messages(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Q1']);
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'A1']);

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(2, $context);
        $this->assertInstanceOf(LlmChatMessage::class, $context[0]);
        $this->assertSame('user', $context[0]->role);
        $this->assertSame('Q1', $context[0]->content);
        $this->assertSame('assistant', $context[1]->role);
    }

    public function test_build_context_window_respects_window_size(): void
    {
        $session = ChatSession::factory()->create();

        foreach (range(1, 8) as $i) {
            ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'content' => "msg{$i}"]);
        }

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(5, $context); // contextWindowSize = 5
        $this->assertSame('msg8', $context[4]->content); // last message is most recent
    }

    public function test_build_context_window_prepends_summary_as_system_message(): void
    {
        $session = ChatSession::factory()->create([
            'context_summary' => 'User asked about red shoes.',
        ]);
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Any updates?']);

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(2, $context);
        $this->assertSame('system', $context[0]->role);
        $this->assertStringContainsString('User asked about red shoes.', $context[0]->content);
        $this->assertSame('user', $context[1]->role);
    }

    public function test_build_context_window_skips_summary_when_null(): void
    {
        $session = ChatSession::factory()->create(['context_summary' => null]);
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hi']);

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(1, $context);
        $this->assertSame('user', $context[0]->role);
    }

    public function test_build_context_window_hydrates_tool_calls_as_dto_objects(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['query' => 'laptop']],
            ],
        ]);

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(1, $context);
        $this->assertIsArray($context[0]->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $context[0]->toolCalls[0]);
        $this->assertSame('call_1', $context[0]->toolCalls[0]->id);
        $this->assertSame('search_products', $context[0]->toolCalls[0]->name);
        $this->assertSame(['query' => 'laptop'], $context[0]->toolCalls[0]->arguments);
    }

    public function test_build_context_window_includes_tool_call_id_for_tool_messages(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'content' => '{"results":[]}',
            'tool_call_id' => 'call_1',
        ]);

        $context = $this->service->buildContextWindow($session);

        $this->assertCount(1, $context);
        $this->assertSame('call_1', $context[0]->toolCallId);
    }

    // ── needsSummarization ────────────────────────────────────────────────────

    public function test_needs_summarization_returns_false_below_threshold(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(10)->create(['session_id' => $session->id]);

        $this->assertFalse($this->service->needsSummarization($session));
    }

    public function test_needs_summarization_returns_true_above_threshold(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(11)->create(['session_id' => $session->id]);

        $this->assertTrue($this->service->needsSummarization($session));
    }
}
