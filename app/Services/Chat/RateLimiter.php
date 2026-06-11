<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Services\Chat\DTO\RateLimitResult;
use App\Settings\BotRateLimitSettings;
use Illuminate\Redis\Connections\Connection;

/**
 * Checks three independent rate-limit tiers using Redis INCR + EXPIRE.
 *
 * Each tier uses a per-minute sliding bucket keyed by the Unix minute
 * (floor(time() / 60)), so the window resets naturally after 60 seconds
 * with no explicit cleanup needed.
 *
 * Tiers (checked in order of strictness):
 *   1. Per-session RPM  – chat:rl:sess:{id}:{minute}
 *   2. Per-IP RPM       – chat:rl:ip:{ip}:{minute}
 *   3. Global RPM       – chat:rl:global:{minute}
 *
 * Returns a RateLimitResult without throwing; callers decide what to do.
 */
final class RateLimiter
{
    /** Keys expire after two minutes so the bucket is auto-cleaned up. */
    private const int KEY_TTL_SECONDS = 120;

    public function __construct(
        private readonly Connection $redis,
        private readonly BotRateLimitSettings $settings,
    ) {
    }

    public function check(string $sessionId, string $ip): RateLimitResult
    {
        $minute = $this->currentMinuteBucket();

        if (! $this->isWithinLimit($this->sessionKey($sessionId, $minute), $this->settings->rateLimitSessionRpm)) {
            return RateLimitResult::denied('session', $this->secondsUntilNextMinute());
        }

        if (! $this->isWithinLimit($this->ipKey($ip, $minute), $this->settings->rateLimitIpRpm)) {
            return RateLimitResult::denied('ip', $this->secondsUntilNextMinute());
        }

        if (! $this->isWithinLimit($this->globalKey($minute), $this->settings->rateLimitGlobalRpm)) {
            return RateLimitResult::denied('global', $this->secondsUntilNextMinute());
        }

        return RateLimitResult::allowed();
    }

    /**
     * Increment a counter and return whether it is still within the given limit.
     * Sets TTL on the first increment; subsequent calls skip EXPIRE to avoid
     * resetting the window on every request.
     */
    private function isWithinLimit(string $key, int $limit): bool
    {
        $count = (int) $this->redis->incr($key);

        if ($count === 1) {
            $this->redis->expire($key, self::KEY_TTL_SECONDS);
        }

        return $count <= $limit;
    }

    /** Unix minute bucket (changes every 60 seconds). */
    private function currentMinuteBucket(): int
    {
        return (int) floor(time() / 60);
    }

    /** Seconds remaining until the current minute rolls over (max 60). */
    private function secondsUntilNextMinute(): int
    {
        return 60 - (time() % 60);
    }

    // ── Key builders ─────────────────────────────────────────────────────────

    private function sessionKey(string $sessionId, int $minute): string
    {
        return "chat:rl:sess:{$sessionId}:{$minute}";
    }

    private function ipKey(string $ip, int $minute): string
    {
        return "chat:rl:ip:{$ip}:{$minute}";
    }

    private function globalKey(int $minute): string
    {
        return "chat:rl:global:{$minute}";
    }
}
