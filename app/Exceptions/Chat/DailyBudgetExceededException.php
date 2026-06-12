<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class DailyBudgetExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Daily LLM budget has been exhausted.');
    }
}
