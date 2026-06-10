<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class SessionNotFoundException extends RuntimeException
{
    public function __construct(string $sessionId)
    {
        parent::__construct("Chat session not found or expired: {$sessionId}");
    }
}
