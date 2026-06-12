<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\Chat\SessionNotFoundException;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ConversationServiceInterface;
use App\Services\Chat\DTO\LlmChatMessage;
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
     *     tool_calls?: array<mixed>,
     *     tool_name?: string,
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
            'model' => $options['model'] ?? null,
            'tokens_used' => $options['tokens_used'] ?? null,
            'latency_ms' => $options['latency_ms'] ?? null,
            'fallback_used' => $options['fallback_used'] ?? false,
            'tool_calls' => $options['tool_calls'] ?? null,
            'tool_name' => $options['tool_name'] ?? null,
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
                toolCalls: $message->tool_calls,
                toolCallId: null,
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
}
