<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

interface NotificationChannelInterface
{
    public function send(string $subject, string $body): void;
}
