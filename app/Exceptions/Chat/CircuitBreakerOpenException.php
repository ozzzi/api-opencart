<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $model,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            "Circuit breaker open for model [{$model}]. Retry after {$retryAfterSeconds}s.",
        );
    }
}
