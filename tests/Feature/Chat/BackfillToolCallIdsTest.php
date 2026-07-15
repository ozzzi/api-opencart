<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackfillToolCallIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_single_tool_call_id_from_preceding_assistant_announcement(): void
    {
        $session = ChatSession::factory()->create();

        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'search_products', 'arguments' => ['query' => 'laptop']],
            ],
        ]);

        $toolResult = ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'content' => '{"products": []}',
            'tool_name' => 'search_products',
            'tool_call_id' => null,
        ]);

        $this->artisan('chat:backfill-tool-call-ids')->assertSuccessful();

        $this->assertSame('call_1', $toolResult->fresh()->tool_call_id);
    }

    public function test_backfills_multiple_tool_calls_in_correct_order(): void
    {
        $session = ChatSession::factory()->create();

        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'search_products', 'arguments' => []],
                ['id' => 'call_2', 'name' => 'search_knowledge_base', 'arguments' => []],
            ],
        ]);

        $first = ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'tool_name' => 'search_products',
            'tool_call_id' => null,
        ]);

        $second = ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'tool_name' => 'search_knowledge_base',
            'tool_call_id' => null,
        ]);

        $this->artisan('chat:backfill-tool-call-ids')->assertSuccessful();

        $this->assertSame('call_1', $first->fresh()->tool_call_id);
        $this->assertSame('call_2', $second->fresh()->tool_call_id);
    }

    public function test_does_not_touch_rows_that_already_have_a_tool_call_id(): void
    {
        $session = ChatSession::factory()->create();

        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'search_products', 'arguments' => []],
            ],
        ]);

        $toolResult = ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'tool_name' => 'search_products',
            'tool_call_id' => 'call_already_set',
        ]);

        $this->artisan('chat:backfill-tool-call-ids')->assertSuccessful();

        $this->assertSame('call_already_set', $toolResult->fresh()->tool_call_id);
    }

    public function test_skips_orphaned_tool_result_with_no_preceding_announcement(): void
    {
        $session = ChatSession::factory()->create();

        $orphan = ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'tool',
            'tool_name' => 'search_products',
            'tool_call_id' => null,
        ]);

        $this->artisan('chat:backfill-tool-call-ids')->assertSuccessful();

        $this->assertNull($orphan->fresh()->tool_call_id);
    }

    public function test_reports_success_when_nothing_to_backfill(): void
    {
        $this->artisan('chat:backfill-tool-call-ids')
            ->expectsOutputToContain('No legacy rows to backfill.')
            ->assertSuccessful();
    }
}
