<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Models\Bot\MessageFeedback;
use App\Settings\BotChatSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

final class ChatHistoryPartsTest extends TestCase
{
    use RefreshDatabase;

    private ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $settings->sessionTtlMinutes = 60;
        $this->app->instance(BotChatSettings::class, $settings);

        $this->session = ChatSession::factory()->create(['last_activity_at' => now()]);
    }

    public function test_stored_parts_are_returned_verbatim(): void
    {
        $parts = [
            ['type' => 'text', 'text' => 'Ось три варіанти:'],
            ['type' => 'products', 'items' => [['id' => 42, 'name' => 'Acer Aspire 5']]],
            ['type' => 'text', 'text' => 'Перший помітно легший.'],
        ];

        ChatMessage::factory()->withParts($parts)->create(['session_id' => $this->session->id]);

        $message = $this->history()->json('data.0');

        $this->assertSame($parts, $message['parts']);
        $this->assertSame('assistant', $message['role']);
    }

    public function test_a_user_message_becomes_a_single_text_part(): void
    {
        ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'user',
            'content' => 'Потрібен ноутбук до 30000',
            'parts' => null,
        ]);

        $this->assertSame(
            [['type' => 'text', 'text' => 'Потрібен ноутбук до 30000']],
            $this->history()->json('data.0.parts'),
        );
    }

    public function test_an_assistant_row_written_before_parts_existed_still_renders(): void
    {
        ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
            'content' => 'Стара відповідь.',
            'parts' => null,
        ]);

        $this->assertSame(
            [['type' => 'text', 'text' => 'Стара відповідь.']],
            $this->history()->json('data.0.parts'),
        );
    }

    public function test_tool_call_rows_are_not_surfaced_as_messages(): void
    {
        ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'tc-1', 'name' => 'show_products', 'arguments' => []]],
        ]);
        ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
            'content' => 'Ось варіанти.',
        ]);

        $data = $this->history()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Ось варіанти.', $data[0]['parts'][0]['text']);
    }

    public function test_feedback_is_present_and_null_when_the_message_was_not_rated(): void
    {
        ChatMessage::factory()->create(['session_id' => $this->session->id, 'role' => 'assistant']);

        $message = $this->history()->json('data.0');

        $this->assertArrayHasKey('feedback', $message);
        $this->assertNull($message['feedback']);
    }

    public function test_feedback_carries_the_rating_when_the_message_was_rated(): void
    {
        $message = ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
        ]);

        MessageFeedback::create([
            'message_id' => $message->id,
            'session_id' => $this->session->id,
            'rating' => 1,
        ]);

        $this->assertSame(1, $this->history()->json('data.0.feedback'));
    }

    public function test_message_id_and_created_at_are_exposed_for_the_feedback_button(): void
    {
        $message = ChatMessage::factory()->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
        ]);

        $payload = $this->history()->json('data.0');

        $this->assertSame($message->id, $payload['message_id']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $payload['created_at'],
        );
    }

    public function test_the_flat_pagination_envelope_is_preserved(): void
    {
        ChatMessage::factory()->count(3)->create([
            'session_id' => $this->session->id,
            'role' => 'assistant',
        ]);

        $response = $this->history();

        $response->assertJsonStructure(['current_page', 'data', 'per_page', 'total', 'last_page']);
        $this->assertSame(3, $response->json('total'));
    }

    private function history(): TestResponse
    {
        return $this->getJson(route('chat.history'), ['X-Chat-Session' => $this->session->id])
            ->assertOk();
    }
}
