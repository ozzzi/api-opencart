<?php

declare(strict_types=1);

namespace App\Services\Chat\Notifications;

use App\Mail\AlertMail;
use App\Services\Chat\Contracts\NotificationChannelInterface;
use App\Settings\BotNotificationSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class EmailNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly BotNotificationSettings $settings,
    ) {
    }

    public function send(string $subject, string $body): void
    {
        if (! $this->settings->leadEmailEnabled || $this->settings->leadEmailRecipient === '') {
            return;
        }

        try {
            Mail::to($this->settings->leadEmailRecipient)
                ->send(new AlertMail($subject, $body));
        } catch (Throwable $e) {
            Log::channel('chat')->error('Email notification failed', ['error' => $e->getMessage()]);
        }
    }
}
