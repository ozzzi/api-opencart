<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Jobs\SendNewDialogNotificationJob;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\AlertNotifierInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SendNewDialogNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var MockInterface&AlertNotifierInterface */
    private MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifier = Mockery::mock(AlertNotifierInterface::class);
    }

    // ── notify payload ────────────────────────────────────────────────────────

    public function test_notifies_once_with_a_non_empty_subject_and_body(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Добрий день, у вас є ноутбуки до 30000?',
        ]);

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject, string $body): bool => $subject !== '' && $body !== '');

        $this->runJob($session->id);
    }

    public function test_subject_previews_the_first_message(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Добрий день, у вас є ноутбуки?',
        ]);

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject): bool => str_contains($subject, 'Добрий день'));

        $this->runJob($session->id);
    }

    public function test_body_contains_the_full_message_preview_and_the_conversation_link(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Потрібна консультація щодо доставки',
        ]);

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(function (string $subject, string $body) use ($session): bool {
                return str_contains($body, 'Потрібна консультація щодо доставки')
                    && str_contains($body, (string) $session->id)
                    && str_contains($body, route('admin.conversations.show', $session->id));
            });

        $this->runJob($session->id);
    }

    public function test_falls_back_to_a_generic_subject_when_there_is_no_user_message(): void
    {
        $session = ChatSession::factory()->create();

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject): bool => $subject === 'New dialog started');

        $this->runJob($session->id);
    }

    public function test_ignores_assistant_and_tool_messages_when_building_the_preview(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Вітаю! Чим можу допомогти?',
        ]);

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject): bool => $subject === 'New dialog started');

        $this->runJob($session->id);
    }

    // ── missing session ──────────────────────────────────────────────────────

    public function test_throws_when_session_does_not_exist(): void
    {
        $this->notifier->shouldNotReceive('notify');

        $this->expectException(ModelNotFoundException::class);

        $this->runJob('00000000-0000-0000-0000-000000000000');
    }

    // ── queue ─────────────────────────────────────────────────────────────────

    public function test_is_queued_on_the_notifications_queue(): void
    {
        $job = new SendNewDialogNotificationJob('any-session-id');

        $this->assertSame('notifications', $job->queue);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function runJob(string $sessionId): void
    {
        $job = new SendNewDialogNotificationJob($sessionId);
        $job->handle($this->notifier);
    }
}
