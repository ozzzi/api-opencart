<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat;

use App\Jobs\SummarizeConversationJob;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\UsageStats;
use App\Settings\BotChatSettings;
use App\Settings\BotLlmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

final class SummarizeConversationJobTest extends TestCase
{
    use RefreshDatabase;

    private BotChatSettings $chatSettings;

    private BotLlmSettings $llmSettings;

    private MockInterface $llmClient;

    private LlmResponse $stubResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chatSettings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $this->chatSettings->contextWindowSize = 5;
        $this->chatSettings->summarizationPrompt = 'Summarize the conversation.';

        $this->llmSettings = (new ReflectionClass(BotLlmSettings::class))->newInstanceWithoutConstructor();
        $this->llmSettings->primaryModel = 'gpt-4o-mini';

        $this->stubResponse = new LlmResponse(
            content: 'User asked about shoes.',
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 50, completionTokens: 20, costUsd: 0.001),
        );

        $this->llmClient = Mockery::mock(LlmClientInterface::class);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_summarizes_old_messages_and_saves_to_session(): void
    {
        $session = ChatSession::factory()->create(['context_summary' => null]);
        ChatMessage::factory()->count(8)->create(['session_id' => $session->id]);

        $this->llmClient->shouldReceive('complete')->once()->andReturn($this->stubResponse);

        $this->runJob($session->id);

        $session->refresh();
        $this->assertSame('User asked about shoes.', $session->context_summary);
    }

    public function test_deletes_summarized_messages_keeps_context_window(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(8)->create(['session_id' => $session->id]);

        $this->llmClient->shouldReceive('complete')->once()->andReturn($this->stubResponse);

        $this->runJob($session->id);

        $this->assertSame(5, $session->messages()->count());
    }

    public function test_appends_to_existing_summary(): void
    {
        $session = ChatSession::factory()->create([
            'context_summary' => 'Previous summary.',
        ]);
        ChatMessage::factory()->count(8)->create(['session_id' => $session->id]);

        $this->llmClient->shouldReceive('complete')->once()->andReturn($this->stubResponse);

        $this->runJob($session->id);

        $session->refresh();
        $this->assertStringContainsString('Previous summary.', $session->context_summary);
        $this->assertStringContainsString('User asked about shoes.', $session->context_summary);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_does_nothing_when_session_not_found(): void
    {
        $this->llmClient->shouldNotReceive('complete');

        $this->runJob('00000000-0000-0000-0000-000000000000');
    }

    public function test_does_nothing_when_messages_within_window_size(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(5)->create(['session_id' => $session->id]);

        $this->llmClient->shouldNotReceive('complete');

        $this->runJob($session->id);

        $this->assertSame(5, $session->messages()->count());
    }

    public function test_does_nothing_when_llm_returns_empty_content(): void
    {
        $session = ChatSession::factory()->create(['context_summary' => null]);
        ChatMessage::factory()->count(8)->create(['session_id' => $session->id]);

        $emptyResponse = new LlmResponse(
            content: null,
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 50, completionTokens: 0, costUsd: 0.0),
        );
        $this->llmClient->shouldReceive('complete')->once()->andReturn($emptyResponse);

        $this->runJob($session->id);

        $session->refresh();
        $this->assertNull($session->context_summary);
        $this->assertSame(8, $session->messages()->count());
    }

    public function test_job_targets_chat_queue(): void
    {
        $job = new SummarizeConversationJob('some-id');

        $this->assertSame('chat', $job->queue);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function runJob(string $sessionId): void
    {
        $job = new SummarizeConversationJob($sessionId);
        $job->handle($this->llmClient, $this->chatSettings, $this->llmSettings);
    }
}
