<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $retryAfterSeconds,
        public readonly ?string $userMessage = null,
    ) {
        parent::__construct(
            "Rate limit exceeded [{$limitType}]. Retry after {$retryAfterSeconds}s.",
        );
    }
}
