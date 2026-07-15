<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\DTO\LlmChatMessage;

interface ConversationServiceInterface
{
    public function createSession(string $ip, string $userAgent, string $lang): ChatSession;

    public function getSession(string $sessionId): ChatSession;

    /**
     * @param array{
     *     model?: string,
     *     tokens_used?: int,
     *     latency_ms?: int,
     *     fallback_used?: bool,
     *     tool_calls?: array<mixed>,
     *     tool_name?: string,
     *     tool_call_id?: string,
     * } $options
     */
    public function addMessage(ChatSession $session, string $role, string $content, array $options = []): ChatMessage;

    /** @return array<LlmChatMessage> */
    public function buildContextWindow(ChatSession $session): array;

    public function needsSummarization(ChatSession $session): bool;
}
