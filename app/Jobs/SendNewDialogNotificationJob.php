<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\AlertNotifierInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

#[Tries(3)]
#[Backoff([10, 30, 60])]
final class SendNewDialogNotificationJob implements ShouldQueue
{
    use Queueable;

    private const int PREVIEW_LENGTH = 200;

    public function __construct(
        private readonly string $sessionId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(AlertNotifierInterface $notifier): void
    {
        $session = ChatSession::findOrFail($this->sessionId);

        $firstMessage = (string) ($session->messages()
            ->where('role', 'user')
            ->oldest()
            ->value('content') ?? '');

        $notifier->notify(
            $this->buildSubject($firstMessage),
            $this->buildBody($session, $firstMessage),
        );
    }

    private function buildSubject(string $firstMessage): string
    {
        if ($firstMessage === '') {
            return 'New dialog started';
        }

        return 'New dialog: '.Str::limit($firstMessage, 60);
    }

    private function buildBody(ChatSession $session, string $firstMessage): string
    {
        $lines = [
            "Session: {$session->id}",
            "Started: {$session->created_at->toDateTimeString()}",
            'IP: '.($session->ip_address ?? 'unknown'),
        ];

        if ($firstMessage !== '') {
            $lines[] = '';
            $lines[] = 'Message: "'.Str::limit($firstMessage, self::PREVIEW_LENGTH).'"';
        }

        $lines[] = '';
        $lines[] = route('admin.conversations.show', $session->id);

        return implode("\n", $lines);
    }
}
