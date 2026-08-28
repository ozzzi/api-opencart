<?php

declare(strict_types=1);

namespace App\Services\Chat\Notifications;

use App\Services\Chat\Contracts\NotificationChannelInterface;
use App\Settings\BotNotificationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly BotNotificationSettings $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->leadTelegramEnabled
            && $this->settings->leadTelegramBotToken !== ''
            && $this->settings->leadTelegramChatId !== '';
    }

    public function send(string $subject, string $body): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::post(
                "https://api.telegram.org/bot{$this->settings->leadTelegramBotToken}/sendMessage",
                [
                    'chat_id' => $this->settings->leadTelegramChatId,
                    'text' => "<b>{$subject}</b>\n\n{$body}",
                    'parse_mode' => 'HTML',
                ],
            );
        } catch (Throwable $e) {
            Log::channel('chat')->error('Telegram notification failed', ['error' => $e->getMessage()]);
        }
    }
}
