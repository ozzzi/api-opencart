<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat;

use App\Jobs\BudgetThresholdAlertJob;
use App\Services\Chat\Contracts\AlertNotifierInterface;
use App\Services\Chat\Contracts\CostTrackerInterface;
use App\Settings\BotRateLimitSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

final class BudgetThresholdAlertJobTest extends TestCase
{
    private BotRateLimitSettings $rateLimitSettings;

    /** @var MockInterface&CostTrackerInterface */
    private MockInterface $costTracker;

    /** @var MockInterface&AlertNotifierInterface */
    private MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimitSettings = (new ReflectionClass(BotRateLimitSettings::class))->newInstanceWithoutConstructor();
        $this->rateLimitSettings->dailyBudgetUsd = 10.0;
        $this->rateLimitSettings->budgetAlertThreshold = 0.8;

        $this->costTracker = Mockery::mock(CostTrackerInterface::class);
        $this->notifier = Mockery::mock(AlertNotifierInterface::class);
    }

    // ── threshold check ───────────────────────────────────────────────────────

    public function test_does_nothing_when_below_threshold(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(7.9);
        Redis::shouldReceive('exists')->never();
        $this->notifier->shouldReceive('notify')->never();

        $this->runJob();
    }

    public function test_sends_alert_when_threshold_reached(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(8.0);

        $today = Carbon::today()->toDateString();
        Redis::shouldReceive('exists')->once()->with("chat:budget_alert_sent:{$today}")->andReturn(0);
        Redis::shouldReceive('setex')->once();

        $this->notifier->shouldReceive('notify')->once();

        $this->runJob();
    }

    public function test_sends_alert_when_threshold_exceeded(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(10.5);

        Redis::shouldReceive('exists')->once()->andReturn(0);
        Redis::shouldReceive('setex')->once();

        $this->notifier->shouldReceive('notify')->once();

        $this->runJob();
    }

    // ── deduplication flag ────────────────────────────────────────────────────

    public function test_skips_alert_when_flag_already_set(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(9.0);

        $today = Carbon::today()->toDateString();
        Redis::shouldReceive('exists')->once()->with("chat:budget_alert_sent:{$today}")->andReturn(1);
        Redis::shouldReceive('setex')->never();

        $this->notifier->shouldReceive('notify')->never();

        $this->runJob();
    }

    public function test_sets_flag_with_24h_ttl_after_sending(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(8.5);

        $today = Carbon::today()->toDateString();
        Redis::shouldReceive('exists')->once()->andReturn(0);
        Redis::shouldReceive('setex')
            ->once()
            ->with("chat:budget_alert_sent:{$today}", 86400, '1');

        $this->notifier->shouldReceive('notify')->once();

        $this->runJob();
    }

    // ── budget guard ──────────────────────────────────────────────────────────

    public function test_does_nothing_when_budget_is_zero(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(5.0);
        $this->rateLimitSettings->dailyBudgetUsd = 0.0;

        Redis::shouldReceive('exists')->never();
        $this->notifier->shouldReceive('notify')->never();

        $this->runJob();
    }

    // ── notify payload ────────────────────────────────────────────────────────

    public function test_notify_receives_subject_and_body(): void
    {
        $this->costTracker->shouldReceive('getDailyCost')->once()->andReturn(9.0);

        Redis::shouldReceive('exists')->once()->andReturn(0);
        Redis::shouldReceive('setex')->once();

        $this->notifier
            ->shouldReceive('notify')
            ->once()
            ->withArgs(function (string $subject, string $body): bool {
                return str_contains($subject, '90%')
                    && str_contains($body, '$9')
                    && str_contains($body, '$10');
            });

        $this->runJob();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        $job = new BudgetThresholdAlertJob();
        $job->handle($this->costTracker, $this->rateLimitSettings, $this->notifier);
    }
}
