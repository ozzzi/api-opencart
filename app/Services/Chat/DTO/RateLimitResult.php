<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public ?string $limitType = null,
        public int $retryAfterSeconds = 60,
        public ?string $message = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(allowed: true);
    }

    public static function denied(string $limitType, int $retryAfterSeconds = 60, ?string $message = null): self
    {
        return new self(
            allowed: false,
            limitType: $limitType,
            retryAfterSeconds: $retryAfterSeconds,
            message: $message,
        );
    }
}
