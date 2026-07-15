<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class DailyBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly float $currentSpendUsd = 0.0,
        public readonly float $dailyBudgetUsd = 0.0,
    ) {
        parent::__construct(
            "Daily LLM budget exhausted. Spend: {$currentSpendUsd}, budget: {$dailyBudgetUsd}.",
        );
    }
}
