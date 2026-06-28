<?php

declare(strict_types=1);

namespace App\Services\Chat\CircuitBreaker;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;

/**
 * Redis-backed circuit breaker for LLM model calls.
 *
 * State machine:
 *   closed ──(failures ≥ threshold)──► open
 *   open   ──(recovery timeout elapsed)──► half_open
 *   half_open ──(success)──► closed
 *   half_open ──(failure)──► open
 *
 * The Redis connection is injected so that BotServiceProvider can bind
 * a specific connection (e.g. 'default') and tests can provide a mock.
 */
final class CircuitBreaker implements CircuitBreakerInterface
{
    private const string STATE_CLOSED = 'closed';

    private const string STATE_OPEN = 'open';

    private const string STATE_HALF_OPEN = 'half_open';

    private readonly int $failureThreshold;

    private readonly int $recoveryTimeoutSec;

    public function __construct(private readonly Connection $redis)
    {
        $this->failureThreshold = (int) config('bot.circuit_breaker.failure_threshold', 5);
        $this->recoveryTimeoutSec = (int) config('bot.circuit_breaker.recovery_timeout_sec', 60);
    }

    /**
     * Check whether the model is available for a call.
     * Transitions open → half_open automatically when the recovery window has passed.
     */
    public function isAvailable(string $model): bool
    {
        $state = $this->getState($model);

        if ($state === self::STATE_CLOSED || $state === self::STATE_HALF_OPEN) {
            return true;
        }

        // STATE_OPEN: check if the recovery window has elapsed
        $openUntil = (int) $this->redis->get($this->keyOpenUntil($model));

        if (time() >= $openUntil) {
            $this->transitionTo($model, self::STATE_HALF_OPEN);

            return true;
        }

        return false;
    }

    /**
     * Record a successful call. Resets failure counter and closes the circuit.
     */
    public function recordSuccess(string $model): void
    {
        $previousState = $this->getState($model);

        $this->redis->del(
            $this->keyState($model),
            $this->keyFailures($model),
            $this->keyOpenUntil($model),
        );

        if ($previousState !== self::STATE_CLOSED) {
            Log::channel('chat')->info('Circuit breaker closed', [
                'model' => $model,
                'previous_state' => $previousState,
            ]);
        }
    }

    /**
     * Record a failed call. Opens the circuit when the failure threshold is reached.
     */
    public function recordFailure(string $model): void
    {
        $failures = (int) $this->redis->incr($this->keyFailures($model));

        // Keep the counter alive long enough to cover the recovery window
        $this->redis->expire($this->keyFailures($model), $this->recoveryTimeoutSec * 4);

        Log::channel('chat')->warning('Circuit breaker failure recorded', [
            'model' => $model,
            'failures' => $failures,
            'threshold' => $this->failureThreshold,
        ]);

        if ($failures >= $this->failureThreshold) {
            $this->redis->set($this->keyOpenUntil($model), time() + $this->recoveryTimeoutSec);
            $this->transitionTo($model, self::STATE_OPEN);

            Log::channel('chat')->error('Circuit breaker opened', [
                'model' => $model,
                'failures' => $failures,
                'recovery_timeout_sec' => $this->recoveryTimeoutSec,
            ]);
        }
    }

    /**
     * Return the current state for a model ('closed', 'open', or 'half_open').
     */
    public function getState(string $model): string
    {
        return (string) ($this->redis->get($this->keyState($model)) ?? self::STATE_CLOSED);
    }

    /**
     * Return seconds until the circuit will attempt recovery (0 when already available).
     */
    public function retryAfterSeconds(string $model): int
    {
        $openUntil = (int) $this->redis->get($this->keyOpenUntil($model));

        return max(0, $openUntil - time());
    }

    // ── Key helpers ──────────────────────────────────────────────────────────

    private function keyState(string $model): string
    {
        return "chat:circuit:{$model}:state";
    }

    private function keyFailures(string $model): string
    {
        return "chat:circuit:{$model}:failures";
    }

    private function keyOpenUntil(string $model): string
    {
        return "chat:circuit:{$model}:open_until";
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function transitionTo(string $model, string $state): void
    {
        // Keep state key alive well beyond the recovery window so it is
        // always readable; it is deleted explicitly on recordSuccess().
        $this->redis->setex($this->keyState($model), $this->recoveryTimeoutSec * 4, $state);
    }
}
