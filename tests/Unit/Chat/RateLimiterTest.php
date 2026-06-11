<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\DTO\RateLimitResult;
use App\Services\Chat\RateLimiter;
use App\Settings\BotRateLimitSettings;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    /** @var array<string, int> in-memory counter store for the mock Redis */
    private array $store = [];

    private MockInterface $redis;
    private BotRateLimitSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];
        $this->redis = $this->buildRedisMock();

        $this->settings = (new ReflectionClass(BotRateLimitSettings::class))->newInstanceWithoutConstructor();
        $this->settings->rateLimitSessionRpm = 10;
        $this->settings->rateLimitIpRpm = 20;
        $this->settings->rateLimitGlobalRpm = 100;
        $this->settings->dailyBudgetUsd = 10.0;
        $this->settings->budgetAlertThreshold = 0.8;
    }

    // ── RateLimitResult DTO ───────────────────────────────────────────────────

    public function test_rate_limit_result_allowed_factory(): void
    {
        $result = RateLimitResult::allowed();

        $this->assertTrue($result->allowed);
        $this->assertNull($result->limitType);
    }

    public function test_rate_limit_result_denied_factory(): void
    {
        $result = RateLimitResult::denied('session', 45, 'Too many requests.');

        $this->assertFalse($result->allowed);
        $this->assertSame('session', $result->limitType);
        $this->assertSame(45, $result->retryAfterSeconds);
        $this->assertSame('Too many requests.', $result->message);
    }

    // ── check: happy path ─────────────────────────────────────────────────────

    public function test_check_allows_when_all_tiers_are_within_limits(): void
    {
        $result = $this->make()->check('sess-abc', '127.0.0.1');

        $this->assertTrue($result->allowed);
        $this->assertNull($result->limitType);
    }

    public function test_check_allows_up_to_session_limit(): void
    {
        $limiter = $this->make();

        for ($i = 0; $i < 10; $i++) {
            $result = $limiter->check('sess-abc', '127.0.0.1');
            $this->assertTrue($result->allowed, "Expected allowed on request #{$i}");
        }
    }

    // ── check: session tier ───────────────────────────────────────────────────

    public function test_check_denies_when_session_limit_is_exceeded(): void
    {
        $limiter = $this->make();

        for ($i = 0; $i < 10; $i++) {
            $limiter->check('sess-abc', '127.0.0.1');
        }

        $result = $limiter->check('sess-abc', '127.0.0.1');

        $this->assertFalse($result->allowed);
        $this->assertSame('session', $result->limitType);
        $this->assertGreaterThan(0, $result->retryAfterSeconds);
        $this->assertLessThanOrEqual(60, $result->retryAfterSeconds);
    }

    public function test_session_limit_is_per_session_id(): void
    {
        $limiter = $this->make();

        // Exhaust session A
        for ($i = 0; $i < 10; $i++) {
            $limiter->check('sess-a', '127.0.0.1');
        }

        // Session B should still be allowed
        $result = $limiter->check('sess-b', '127.0.0.1');

        $this->assertTrue($result->allowed);
    }

    // ── check: IP tier ────────────────────────────────────────────────────────

    public function test_check_denies_when_ip_limit_is_exceeded(): void
    {
        $limiter = $this->make();

        // 20 requests from same IP across different sessions
        for ($i = 0; $i < 20; $i++) {
            $limiter->check("sess-{$i}", '192.168.1.1');
        }

        $result = $limiter->check('sess-new', '192.168.1.1');

        $this->assertFalse($result->allowed);
        $this->assertSame('ip', $result->limitType);
    }

    // ── check: global tier ────────────────────────────────────────────────────

    public function test_check_denies_when_global_limit_is_exceeded(): void
    {
        $limiter = $this->make();

        // Exhaust the global limit of 100 using distinct sessions and IPs
        for ($i = 0; $i < 100; $i++) {
            $limiter->check("sess-{$i}", "10.0.0.{$i}");
        }

        $result = $limiter->check('sess-new', '10.0.1.1');

        $this->assertFalse($result->allowed);
        $this->assertSame('global', $result->limitType);
    }

    // ── check: early exit ─────────────────────────────────────────────────────

    public function test_check_stops_at_first_denied_tier(): void
    {
        $limiter = $this->make();

        // Exhaust session limit for sess-abc
        for ($i = 0; $i < 10; $i++) {
            $limiter->check('sess-abc', '127.0.0.1');
        }

        // IP counter should only have 10 hits (session checks happened before IP)
        $result = $limiter->check('sess-abc', '127.0.0.1');

        $this->assertSame('session', $result->limitType);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function make(): RateLimiter
    {
        return new RateLimiter(
            redis: $this->redis,
            settings: $this->settings,
        );
    }

    private function buildRedisMock(): MockInterface
    {
        $mock = Mockery::mock(Connection::class);

        $mock->allows('incr')->andReturnUsing(function (string $key): int {
            $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + 1;

            return (int) $this->store[$key];
        });

        $mock->allows('expire')->andReturnUsing(function (): void {
            // TTL tracking not needed in the in-memory stub
        });

        return $mock;
    }
}
