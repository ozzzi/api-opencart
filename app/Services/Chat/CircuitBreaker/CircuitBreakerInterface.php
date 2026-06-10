<?php

declare(strict_types=1);

namespace App\Services\Chat\CircuitBreaker;

interface CircuitBreakerInterface
{
    public function isAvailable(string $model): bool;

    public function recordSuccess(string $model): void;

    public function recordFailure(string $model): void;

    public function getState(string $model): string;

    public function retryAfterSeconds(string $model): int;
}
