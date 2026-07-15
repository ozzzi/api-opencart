<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Bot\DailyUsageStat;
use App\Models\Bot\LlmApiCall;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Services\Chat\DTO\LlmResponse;
use App\Settings\BotRateLimitSettings;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

final class CostTracker implements CostTrackerInterface
{
    private const string REDIS_KEY_PREFIX = 'chat:daily_cost:';

    /** Seconds in 48 hours — key survives a day after it was created. */
    private const int REDIS_TTL = 172800;

    public function __construct(
        private readonly BotRateLimitSettings $rateLimitSettings,
    ) {
    }

    /**
     * Persists a successful LLM / embedding call and bumps the daily cost counter.
     */
    public function log(
        ?string $sessionId,
        ?int $messageId,
        LlmResponse $response,
        string $model,
        string $type,
        string $provider,
        int $latencyMs,
    ): void {
        LlmApiCall::create([
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'model' => $model,
            'type' => $type,
            'provider' => $provider,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'cost_usd' => $response->usage->costUsd,
            'latency_ms' => $latencyMs,
            'success' => true,
        ]);

        $this->incrementRedis(Carbon::today(), $response->usage->costUsd);
    }

    /**
     * Returns the total spend for a given date.
     * Reads from Redis first; falls back to daily_usage_stats if the key is absent.
     */
    public function getDailyCost(?DateTimeInterface $date = null): float
    {
        $date ??= Carbon::today();
        $key = $this->redisKey($date);

        $cached = Redis::get($key);

        if ($cached !== null) {
            return (float) $cached;
        }

        return (float) (DailyUsageStat::whereDate('date', Carbon::instance($date)->toDateString())
            ->value('total_cost_usd') ?? 0.0);
    }

    /**
     * Returns true when today's spend has not yet reached the daily budget.
     */
    public function checkBudget(): bool
    {
        return $this->getDailyCost() < $this->rateLimitSettings->dailyBudgetUsd;
    }

    /**
     * Returns the configured daily budget cap in USD.
     */
    public function getBudgetCapUsd(): float
    {
        return $this->rateLimitSettings->dailyBudgetUsd;
    }

    private function incrementRedis(DateTimeInterface $date, float $amount): void
    {
        if ($amount <= 0.0) {
            return;
        }

        $key = $this->redisKey($date);
        Redis::incrbyfloat($key, $amount);
        Redis::expire($key, self::REDIS_TTL);
    }

    private function redisKey(DateTimeInterface $date): string
    {
        return self::REDIS_KEY_PREFIX.Carbon::instance($date)->toDateString();
    }
}
