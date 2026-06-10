<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Settings\BotRateLimitSettings;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Redis;

#[Tries(3)]
#[Backoff([5, 30, 60])]
final class BudgetThresholdAlertJob implements ShouldQueue
{
    use Queueable;

    private const string ALERT_FLAG_PREFIX = 'chat:budget_alert_sent:';

    /** 24 hours in seconds. */
    private const int ALERT_FLAG_TTL = 86400;

    public function handle(
        CostTrackerInterface $costTracker,
        BotRateLimitSettings $rateLimitSettings,
        AlertNotifierInterface $notifier,
    ): void {
        $today = Carbon::today();
        $dailyCost = $costTracker->getDailyCost($today);
        $budget = $rateLimitSettings->dailyBudgetUsd;

        if ($budget <= 0.0) {
            return;
        }

        if (($dailyCost / $budget) < $rateLimitSettings->budgetAlertThreshold) {
            return;
        }

        $flagKey = self::ALERT_FLAG_PREFIX.$today->toDateString();

        if (Redis::exists($flagKey)) {
            return;
        }

        $this->sendAlert($dailyCost, $budget, $notifier);

        Redis::setex($flagKey, self::ALERT_FLAG_TTL, '1');
    }

    private function sendAlert(float $dailyCost, float $budget, AlertNotifierInterface $notifier): void
    {
        $percent = round($dailyCost / $budget * 100, 1);
        $date = Carbon::today()->toDateString();

        $subject = "⚠️ Budget alert: {$percent}% used (\${$dailyCost} of \${$budget})";
        $body = "Daily LLM budget threshold reached.\n\nSpent: \${$dailyCost}\nBudget: \${$budget}\nUsage: {$percent}%\nDate: {$date}";

        $notifier->notify($subject, $body);
    }
}
