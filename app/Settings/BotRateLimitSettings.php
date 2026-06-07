<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class BotRateLimitSettings extends Settings
{
    public float $dailyBudgetUsd;

    public float $budgetAlertThreshold;

    public int $rateLimitSessionRpm;

    public int $rateLimitIpRpm;

    public int $rateLimitGlobalRpm;

    public static function group(): string
    {
        return 'bot_rate_limit';
    }
}
