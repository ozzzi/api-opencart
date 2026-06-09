<?php

declare(strict_types=1);

namespace App\Services\Chat\Cost;

/**
 * Stub — full implementation in step 1.4.
 * Calculates LLM API call cost based on model pricing table.
 */
final class CostCalculator
{
    public function calculate(string $model, int $promptTokens, int $completionTokens): float
    {
        return 0.0;
    }
}
