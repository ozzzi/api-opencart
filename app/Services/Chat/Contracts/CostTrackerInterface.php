<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Services\Chat\DTO\LlmResponse;
use DateTimeInterface;

interface CostTrackerInterface
{
    public function log(
        ?string $sessionId,
        ?int $messageId,
        LlmResponse $response,
        string $model,
        string $type,
        string $provider,
        int $latencyMs,
    ): void;

    public function getDailyCost(?DateTimeInterface $date = null): float;

    public function checkBudget(): bool;
}
