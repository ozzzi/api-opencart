<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\Chat\SessionNotFoundException;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\ToolCall;
use App\Settings\BotChatSettings;
use Carbon\Carbon;

final class ConversationService implements ConversationServiceInterface
{
    public function __construct(
        private readonly BotChatSettings $settings,
    ) {
    }

    public function createSession(string $ip, string $userAgent, string $lang): ChatSession
    {
        return ChatSession::create([
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'language' => $lang,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @throws SessionNotFoundException
     */
    public function getSession(string $sessionId): ChatSession
    {
        $session = ChatSession::find($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        $expiredAt = $session->last_activity_at->addMinutes($this->settings->sessionTtlMinutes);

        if (Carbon::now()->isAfter($expiredAt)) {
            throw new SessionNotFoundException($sessionId);
        }

        return $session;
    }

    /**
     * @param array{
     *     model?: string,
     *     tokens_used?: int,
     *     latency_ms?: int,
     *     fallback_used?: bool,
     *     parts?: list<array<string, mixed>>,
     *     tool_calls?: array<mixed>,
     *     tool_name?: string,
     *     tool_call_id?: string,
     * } $options
     */
    public function addMessage(
        ChatSession $session,
        string $role,
        string $content,
        array $options = [],
    ): ChatMessage {
        $message = ChatMessage::create([
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'parts' => $options['parts'] ?? null,
            'model' => $options['model'] ?? null,
            'tokens_used' => $options['tokens_used'] ?? null,
            'latency_ms' => $options['latency_ms'] ?? null,
            'fallback_used' => $options['fallback_used'] ?? false,
            'tool_calls' => $options['tool_calls'] ?? null,
            'tool_name' => $options['tool_name'] ?? null,
            'tool_call_id' => $options['tool_call_id'] ?? null,
        ]);

        $session->last_activity_at = now();
        $session->save();

        return $message;
    }

    /**
     * Returns the last N messages as LlmChatMessage DTOs for the context window.
     * If a summary exists, it is prepended as a system message.
     *
     * @return array<LlmChatMessage>
     */
    public function buildContextWindow(ChatSession $session): array
    {
        $messages = $session->messages()
            ->orderByDesc('id')
            ->limit($this->settings->contextWindowSize)
            ->get()
            ->reverse()
            ->values();

        $context = [];

        if ($session->context_summary !== null && $session->context_summary !== '') {
            $context[] = new LlmChatMessage(
                role: 'system',
                content: "Previous conversation summary:\n{$session->context_summary}",
            );
        }

        foreach ($messages as $message) {
            $context[] = new LlmChatMessage(
                role: $message->role,
                content: $message->content,
                toolCalls: $this->hydrateToolCalls($message->tool_calls),
                toolCallId: $message->tool_call_id,
            );
        }

        return $context;
    }

    /**
     * Returns true when the total message count exceeds the summarization threshold,
     * indicating older messages should be collapsed into a summary.
     */
    public function needsSummarization(ChatSession $session): bool
    {
        return $session->messages()->count() > $this->settings->summaryThreshold;
    }

    /**
     * @return array{rounds: int, opted_out: bool, last_query_terms: list<string>}
     */
    public function getClarificationState(ChatSession $session): array
    {
        $state = $session->clarification_state ?? [];

        return [
            'rounds'           => (int) ($state['rounds'] ?? 0),
            'opted_out'        => (bool) ($state['opted_out'] ?? false),
            'last_query_terms' => array_values(array_map(
                strval(...),
                (array) ($state['last_query_terms'] ?? []),
            )),
        ];
    }

    /**
     * @param array{rounds?: int, opted_out?: bool, last_query_terms?: list<string>} $state
     */
    public function updateClarificationState(ChatSession $session, array $state): void
    {
        $session->clarification_state = [...$this->getClarificationState($session), ...$state];
        $session->save();
    }

    /**
     * Rehydrates the JSON-cast tool_calls column back into ToolCall DTOs.
     *
     * @param array<int, array{id: string, name: string, arguments: array<string, mixed>}>|null $toolCalls
     * @return array<ToolCall>|null
     */
    private function hydrateToolCalls(?array $toolCalls): ?array
    {
        if ($toolCalls === null) {
            return null;
        }

        return array_map(
            static fn (array $tc) => new ToolCall(
                id: $tc['id'],
                name: $tc['name'],
                arguments: $tc['arguments'],
            ),
            $toolCalls,
        );
    }
}
