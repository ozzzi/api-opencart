<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\LlmClientInterface;
use App\Services\Chat\DTO\LlmChatMessage;
use App\Services\Chat\DTO\LlmRequest;
use App\Settings\BotChatSettings;
use App\Settings\BotLlmSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Collection;

#[Tries(3)]
#[Backoff([5, 30, 60])]
final class SummarizeConversationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $sessionId,
    ) {
        $this->onQueue('chat');
    }

    public function handle(
        LlmClientInterface $llmClient,
        BotChatSettings $chatSettings,
        BotLlmSettings $llmSettings,
    ): void {
        $session = ChatSession::find($this->sessionId);

        if ($session === null) {
            return;
        }

        /** @var Collection<int, ChatMessage> $allMessages */
        $allMessages = $session->messages()->orderBy('id')->get();

        $total = $allMessages->count();
        $keepCount = $chatSettings->contextWindowSize;

        if ($total <= $keepCount) {
            return;
        }

        $toSummarize = $allMessages->slice(0, $total - $keepCount);

        $response = $llmClient->complete(new LlmRequest(
            messages: [
                new LlmChatMessage(
                    role: 'system',
                    content: $chatSettings->summarizationPrompt,
                ),
                new LlmChatMessage(
                    role: 'user',
                    content: $this->formatForSummary($toSummarize),
                ),
            ],
            model: $llmSettings->primaryModel,
            maxTokens: 500,
            temperature: 0.3,
        ));

        if ($response->content === null || $response->content === '') {
            return;
        }

        $summary = $response->content;

        if ($session->context_summary !== null && $session->context_summary !== '') {
            $summary = $session->context_summary."\n\n".$summary;
        }

        $session->context_summary = $summary;
        $session->save();

        ChatMessage::whereIn('id', $toSummarize->pluck('id'))->delete();
    }

    /**
     * @param Collection<int, ChatMessage> $messages
     */
    private function formatForSummary(Collection $messages): string
    {
        return $messages
            ->map(fn (ChatMessage $msg): string => "{$msg->role}: {$msg->content}")
            ->implode("\n");
    }
}
