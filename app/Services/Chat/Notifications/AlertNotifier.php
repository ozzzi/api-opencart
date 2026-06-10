<?php

declare(strict_types=1);

namespace App\Services\Chat\Notifications;

use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Contracts\NotificationChannelInterface;

final class AlertNotifier implements AlertNotifierInterface
{
    /** @param NotificationChannelInterface[] $channels */
    public function __construct(
        private readonly array $channels,
    ) {
    }

    public function notify(string $subject, string $body): void
    {
        foreach ($this->channels as $channel) {
            $channel->send($subject, $body);
        }
    }
}
