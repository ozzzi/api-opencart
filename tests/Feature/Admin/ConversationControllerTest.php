<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Bot\AdminUser;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Models\Bot\Lead;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class ConversationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->admin()->create();
    }

    // ── Index ─────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.conversations.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_index_renders_for_authenticated_admin(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertViewIs('admin.conversations.index');
    }

    public function test_index_shows_sessions_with_message_count(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(3)->create(['session_id' => $session->id]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.index'));

        $response->assertOk();
        $sessions = $response->viewData('sessions');
        $this->assertEquals(3, $sessions->first()->messages_count);
    }

    public function test_index_filters_by_language(): void
    {
        ChatSession::factory()->create(['language' => 'uk']);
        ChatSession::factory()->create(['language' => 'ru']);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.index', ['language' => 'uk']));

        $sessions = $response->viewData('sessions');
        $this->assertCount(1, $sessions);
        $this->assertEquals('uk', $sessions->first()->language);
    }

    public function test_index_filters_by_date_from(): void
    {
        ChatSession::factory()->create(['created_at' => now()->subDays(10)]);
        ChatSession::factory()->create(['created_at' => now()->subDay()]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.index', ['date_from' => now()->subDays(3)->toDateString()]));

        $sessions = $response->viewData('sessions');
        $this->assertCount(1, $sessions);
    }

    public function test_index_filters_by_date_to(): void
    {
        ChatSession::factory()->create(['created_at' => now()->subDays(10)]);
        ChatSession::factory()->create(['created_at' => now()]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.index', ['date_to' => now()->subDays(5)->toDateString()]));

        $sessions = $response->viewData('sessions');
        $this->assertCount(1, $sessions);
    }

    // ── Show ──────────────────────────────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $session = ChatSession::factory()->create();

        $this->get(route('admin.conversations.show', $session))
            ->assertRedirect(route('admin.login'));
    }

    public function test_show_renders_conversation_thread(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hello']);
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Hi there']);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.show', $session));

        $response->assertOk()
            ->assertViewIs('admin.conversations.show')
            ->assertSee('Hello')
            ->assertSee('Hi there');
    }

    public function test_show_displays_tool_calls(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role'       => 'assistant',
            'tool_calls' => [['id' => 'call_1', 'name' => 'search_products', 'arguments' => []]],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.show', $session));

        $response->assertOk()->assertSee('tool calls');
    }

    public function test_show_displays_lead_info_when_present(): void
    {
        $session = ChatSession::factory()->create();
        Lead::factory()->create(['session_id' => $session->id, 'name' => 'Іван Петренко']);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.show', $session));

        $response->assertOk()->assertSee('Іван Петренко');
    }

    public function test_show_displays_message_metadata(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id'   => $session->id,
            'role'         => 'assistant',
            'model'        => 'gpt-4o',
            'tokens_used'  => 150,
            'latency_ms'   => 1200,
            'fallback_used' => true,
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.conversations.show', $session));

        $response->assertOk()
            ->assertSee('gpt-4o')
            ->assertSee('150')
            ->assertSee('fallback');
    }
}
