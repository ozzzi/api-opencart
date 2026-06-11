<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Models\Bot\ChatSession;
use App\Models\Bot\DailyUsageStat;
use App\Services\Chat\CostTracker;
use App\Services\Chat\DTO\LlmResponse;
use App\Services\Chat\DTO\UsageStats;
use App\Settings\BotRateLimitSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use ReflectionClass;
use Tests\TestCase;

final class CostTrackerTest extends TestCase
{
    use RefreshDatabase;

    private BotRateLimitSettings $settings;

    private CostTracker $tracker;

    private LlmResponse $stubResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = (new ReflectionClass(BotRateLimitSettings::class))->newInstanceWithoutConstructor();
        $this->settings->dailyBudgetUsd = 10.0;

        $this->tracker = new CostTracker($this->settings);

        $this->stubResponse = new LlmResponse(
            content: 'Hello!',
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 100, completionTokens: 50, costUsd: 0.005),
        );
    }

    // ── log ───────────────────────────────────────────────────────────────────

    public function test_log_persists_llm_api_call_record(): void
    {
        $session = ChatSession::factory()->create();

        Redis::shouldReceive('incrbyfloat')->once();
        Redis::shouldReceive('expire')->once();

        $this->tracker->log($session->id, null, $this->stubResponse, 'gpt-4o', 'chat', 'openai', 250);

        $this->assertDatabaseHas('llm_api_calls', [
            'session_id' => $session->id,
            'model' => 'gpt-4o',
            'type' => 'chat',
            'provider' => 'openai',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'latency_ms' => 250,
            'success' => 1,
        ]);
    }

    public function test_log_accepts_null_message_id(): void
    {
        $session = ChatSession::factory()->create();

        Redis::shouldReceive('incrbyfloat')->once();
        Redis::shouldReceive('expire')->once();

        $this->tracker->log($session->id, null, $this->stubResponse, 'gpt-4o', 'embedding', 'openai', 80);

        $this->assertDatabaseHas('llm_api_calls', [
            'session_id' => $session->id,
            'message_id' => null,
            'type' => 'embedding',
        ]);
    }

    public function test_log_increments_redis_daily_cost(): void
    {
        $session = ChatSession::factory()->create();
        $today = Carbon::today()->toDateString();

        Redis::shouldReceive('incrbyfloat')
            ->once()
            ->with("chat:daily_cost:{$today}", 0.005);

        Redis::shouldReceive('expire')
            ->once()
            ->with("chat:daily_cost:{$today}", 172800);

        $this->tracker->log($session->id, null, $this->stubResponse, 'gpt-4o', 'chat', 'openai', 100);
    }

    public function test_log_skips_redis_increment_for_zero_cost(): void
    {
        $session = ChatSession::factory()->create();

        $zeroResponse = new LlmResponse(
            content: 'Hi',
            toolCalls: [],
            finishReason: 'stop',
            usage: new UsageStats(promptTokens: 10, completionTokens: 5, costUsd: 0.0),
        );

        Redis::shouldReceive('incrbyfloat')->never();
        Redis::shouldReceive('expire')->never();

        $this->tracker->log($session->id, null, $zeroResponse, 'local-model', 'chat', 'local', 50);
    }

    // ── getDailyCost ──────────────────────────────────────────────────────────

    public function test_get_daily_cost_returns_value_from_redis(): void
    {
        $today = Carbon::today()->toDateString();
        Redis::shouldReceive('get')
            ->once()
            ->with("chat:daily_cost:{$today}")
            ->andReturn('3.75');

        $cost = $this->tracker->getDailyCost();

        $this->assertEqualsWithDelta(3.75, $cost, 0.0001);
    }

    public function test_get_daily_cost_falls_back_to_daily_usage_stats(): void
    {
        $date = Carbon::yesterday();
        Redis::shouldReceive('get')
            ->once()
            ->with("chat:daily_cost:{$date->toDateString()}")
            ->andReturnNull();

        DailyUsageStat::create([
            'date' => $date->toDateString(),
            'total_cost_usd' => 7.25,
        ]);

        $cost = $this->tracker->getDailyCost($date);

        $this->assertEqualsWithDelta(7.25, $cost, 0.0001);
    }

    public function test_get_daily_cost_returns_zero_when_no_data(): void
    {
        Redis::shouldReceive('get')->once()->andReturnNull();

        $cost = $this->tracker->getDailyCost();

        $this->assertSame(0.0, $cost);
    }

    public function test_get_daily_cost_uses_today_when_no_date_given(): void
    {
        $today = Carbon::today()->toDateString();
        Redis::shouldReceive('get')
            ->once()
            ->with("chat:daily_cost:{$today}")
            ->andReturn('1.5');

        $this->tracker->getDailyCost();
    }

    // ── checkBudget ───────────────────────────────────────────────────────────

    public function test_check_budget_returns_true_when_under_limit(): void
    {
        Redis::shouldReceive('get')->once()->andReturn('2.0');

        $this->assertTrue($this->tracker->checkBudget());
    }

    public function test_check_budget_returns_false_when_limit_reached(): void
    {
        Redis::shouldReceive('get')->once()->andReturn('10.0');

        $this->assertFalse($this->tracker->checkBudget());
    }

    public function test_check_budget_returns_false_when_limit_exceeded(): void
    {
        Redis::shouldReceive('get')->once()->andReturn('12.5');

        $this->assertFalse($this->tracker->checkBudget());
    }
}
