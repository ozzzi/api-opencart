<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Services\Chat\DTO\RateLimitResult;

interface RateLimiterInterface
{
    public function check(string $sessionId, string $ip): RateLimitResult;
}
