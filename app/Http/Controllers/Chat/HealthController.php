<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\Search\OpenSearchClientFactory;
use App\Settings\BotRateLimitSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
        private readonly BotRateLimitSettings $rateLimitSettings,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $opensearch = $this->checkOpenSearch();
        $redis = $this->checkRedis();

        $dailyCost = $this->costTracker->getDailyCost();
        $budgetRemaining = round(max(0.0, $this->rateLimitSettings->dailyBudgetUsd - $dailyCost), 2);

        return response()->json([
            'status'               => 'ok',
            'opensearch'           => $opensearch,
            'redis'                => $redis,
            'budget_remaining_usd' => $budgetRemaining,
        ]);
    }

    private function checkOpenSearch(): bool
    {
        try {
            OpenSearchClientFactory::make()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
