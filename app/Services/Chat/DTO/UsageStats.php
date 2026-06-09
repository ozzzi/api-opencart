<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class UsageStats
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public float $costUsd,
    ) {
    }
}
