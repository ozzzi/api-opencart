<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\CircuitBreaker;

use App\Services\Chat\CircuitBreaker\CircuitBreaker;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private const string MODEL = 'gpt-4o-mini';

    /** @var array<string, mixed> in-memory store for the mock Redis */
    private array $store = [];

    private MockInterface $redis;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bot.circuit_breaker.failure_threshold' => 3,
            'bot.circuit_breaker.recovery_timeout_sec' => 60,
        ]);

        $this->store = [];
        $this->redis = $this->buildRedisMock();
    }

    // ── isAvailable ───────────────────────────────────────────────────────────

    public function test_new_model_is_available(): void
    {
        $cb = $this->make();

        $this->assertTrue($cb->isAvailable(self::MODEL));
        $this->assertSame('closed', $cb->getState(self::MODEL));
    }

    public function test_stays_available_below_failure_threshold(): void
    {
        $cb = $this->make();

        $cb->recordFailure(self::MODEL); // 1
        $cb->recordFailure(self::MODEL); // 2

        $this->assertTrue($cb->isAvailable(self::MODEL));
        $this->assertSame('closed', $cb->getState(self::MODEL));
    }

    public function test_becomes_unavailable_after_failure_threshold(): void
    {
        $cb = $this->make();

        $cb->recordFailure(self::MODEL); // 1
        $cb->recordFailure(self::MODEL); // 2
        $cb->recordFailure(self::MODEL); // 3 — threshold reached

        $this->assertFalse($cb->isAvailable(self::MODEL));
        $this->assertSame('open', $cb->getState(self::MODEL));
    }

    public function test_returns_retry_after_seconds_when_open(): void
    {
        $this->store["chat:circuit:".self::MODEL.":state"] = 'open';
        $this->store["chat:circuit:".self::MODEL.":open_until"] = time() + 60;

        $cb = $this->make();

        $this->assertGreaterThan(0, $cb->retryAfterSeconds(self::MODEL));
    }

    // ── half_open transition ──────────────────────────────────────────────────

    public function test_transitions_to_half_open_after_recovery_timeout(): void
    {
        // Recovery window already elapsed
        $this->store["chat:circuit:".self::MODEL.":state"] = 'open';
        $this->store["chat:circuit:".self::MODEL.":open_until"] = time() - 1;

        $cb = $this->make();

        $this->assertTrue($cb->isAvailable(self::MODEL));
        $this->assertSame('half_open', $cb->getState(self::MODEL));
    }

    public function test_half_open_state_is_available(): void
    {
        $this->store["chat:circuit:".self::MODEL.":state"] = 'half_open';

        $this->assertTrue($this->make()->isAvailable(self::MODEL));
    }

    // ── recordSuccess ─────────────────────────────────────────────────────────

    public function test_success_resets_to_closed(): void
    {
        $this->store["chat:circuit:".self::MODEL.":state"] = 'open';
        $this->store["chat:circuit:".self::MODEL.":open_until"] = time() + 60;

        $cb = $this->make();
        $this->assertFalse($cb->isAvailable(self::MODEL));

        $this->store["chat:circuit:".self::MODEL.":state"] = 'half_open';
        $cb->recordSuccess(self::MODEL);

        $this->assertTrue($cb->isAvailable(self::MODEL));
        $this->assertSame('closed', $cb->getState(self::MODEL));
    }

    public function test_success_resets_failure_counter(): void
    {
        $cb = $this->make();

        $cb->recordFailure(self::MODEL); // 1
        $cb->recordFailure(self::MODEL); // 2
        $cb->recordSuccess(self::MODEL); // reset

        $cb->recordFailure(self::MODEL); // 1 again
        $cb->recordFailure(self::MODEL); // 2 again

        $this->assertTrue($cb->isAvailable(self::MODEL));
    }

    // ── failure in half_open ──────────────────────────────────────────────────

    public function test_failure_in_half_open_reopens_circuit(): void
    {
        config(['bot.circuit_breaker.failure_threshold' => 1]);
        // Rebuild mock so the new CircuitBreaker picks up updated config
        $this->store = [];
        $this->redis = $this->buildRedisMock();

        $this->store["chat:circuit:".self::MODEL.":state"] = 'half_open';

        $cb = $this->make();
        $cb->recordFailure(self::MODEL);

        $this->assertFalse($cb->isAvailable(self::MODEL));
        $this->assertSame('open', $cb->getState(self::MODEL));
    }

    private function make(): CircuitBreaker
    {
        return new CircuitBreaker($this->redis);
    }

    // ── mock builder ──────────────────────────────────────────────────────────

    /**
     * Build a Mockery mock of the Redis Connection backed by $this->store.
     */
    private function buildRedisMock(): MockInterface
    {
        $mock = Mockery::mock(Connection::class);

        $mock->allows('get')->andReturnUsing(fn (string $key) => $this->store[$key] ?? null);

        $mock->allows('set')->andReturnUsing(function (string $key, mixed $value): void {
            $this->store[$key] = $value;
        });

        $mock->allows('setex')->andReturnUsing(function (string $key, int $ttl, mixed $value): void {
            $this->store[$key] = $value;
        });

        $mock->allows('incr')->andReturnUsing(function (string $key): int {
            $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + 1;

            return (int) $this->store[$key];
        });

        $mock->allows('expire')->andReturnUsing(function (): void {
            // TTL not tracked in the in-memory stub
        });

        $mock->allows('del')->andReturnUsing(function (string ...$keys): void {
            foreach ($keys as $key) {
                unset($this->store[$key]);
            }
        });

        return $mock;
    }
}
