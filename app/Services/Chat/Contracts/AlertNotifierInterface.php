<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

interface AlertNotifierInterface
{
    public function notify(string $subject, string $body): void;

    public function isEnabled(): bool;
}
