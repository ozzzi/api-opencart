<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat;

use App\Jobs\DailyUsageStatsAggregatorJob;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Models\Bot\DailyUsageStat;
use App\Models\Bot\Lead;
use App\Models\Bot\LlmApiCall;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class DailyUsageStatsAggregatorJobTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = Carbon::yesterday()->startOfDay();
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_creates_daily_usage_stat_for_target_date(): void
    {
        Redis::shouldReceive('del')->once();

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
        ]);
    }

    public function test_aggregates_user_message_count(): void
    {
        Redis::shouldReceive('del')->once();

        $session = ChatSession::factory()->create();
        ChatMessage::factory()->count(3)->create([
            'session_id' => $session->id,
            'role' => 'user',
            'created_at' => $this->target,
        ]);
        // assistant messages should not be counted
        ChatMessage::factory()->count(2)->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'created_at' => $this->target,
        ]);

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'total_messages' => 3,
        ]);
    }

    public function test_aggregates_distinct_session_count(): void
    {
        Redis::shouldReceive('del')->once();

        $sessionA = ChatSession::factory()->create();
        $sessionB = ChatSession::factory()->create();
        // 2 messages from session A, 1 from session B
        ChatMessage::factory()->count(2)->create(['session_id' => $sessionA->id, 'role' => 'user', 'created_at' => $this->target]);
        ChatMessage::factory()->create(['session_id' => $sessionB->id, 'role' => 'user', 'created_at' => $this->target]);

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'total_sessions' => 2,
        ]);
    }

    public function test_aggregates_total_cost_from_successful_llm_calls(): void
    {
        Redis::shouldReceive('del')->once();

        LlmApiCall::factory()->create(['cost_usd' => 0.01, 'success' => true, 'latency_ms' => 100, 'created_at' => $this->target]);
        LlmApiCall::factory()->create(['cost_usd' => 0.02, 'success' => true, 'latency_ms' => 200, 'created_at' => $this->target]);
        // failed call should be excluded from cost but included in latency avg? No — we only aggregate successful calls
        LlmApiCall::factory()->create(['cost_usd' => 0.99, 'success' => false, 'latency_ms' => 500, 'created_at' => $this->target]);

        $this->runJob();

        $stat = DailyUsageStat::whereDate('date', $this->target->toDateString())->first();
        $this->assertNotNull($stat);
        $this->assertEqualsWithDelta(0.03, (float) $stat->total_cost_usd, 0.0001);
    }

    public function test_aggregates_avg_latency_from_successful_llm_calls(): void
    {
        Redis::shouldReceive('del')->once();

        LlmApiCall::factory()->create(['cost_usd' => 0.01, 'success' => true, 'latency_ms' => 100, 'created_at' => $this->target]);
        LlmApiCall::factory()->create(['cost_usd' => 0.01, 'success' => true, 'latency_ms' => 300, 'created_at' => $this->target]);

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'avg_latency_ms' => 200,
        ]);
    }

    public function test_counts_non_spam_leads_as_escalations(): void
    {
        Redis::shouldReceive('del')->once();

        Lead::factory()->create(['status' => 'new', 'created_at' => $this->target]);
        Lead::factory()->create(['status' => 'contacted', 'created_at' => $this->target]);
        Lead::factory()->create(['status' => 'spam', 'created_at' => $this->target]); // excluded

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'escalations' => 2,
        ]);
    }

    public function test_upserts_existing_record(): void
    {
        Redis::shouldReceive('del')->once();

        DailyUsageStat::create([
            'date' => $this->target->toDateString(),
            'total_messages' => 999,
            'total_cost_usd' => 99.0,
        ]);

        $session = ChatSession::factory()->create();
        ChatMessage::factory()->create(['session_id' => $session->id, 'role' => 'user', 'created_at' => $this->target]);

        $this->runJob();

        $this->assertSame(1, DailyUsageStat::count());
        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'total_messages' => 1,
        ]);
    }

    public function test_deletes_redis_key_after_aggregation(): void
    {
        $key = "chat:daily_cost:{$this->target->toDateString()}";

        Redis::shouldReceive('del')->once()->with($key);

        $this->runJob();
    }

    public function test_excludes_data_from_other_dates(): void
    {
        Redis::shouldReceive('del')->once();

        $session = ChatSession::factory()->create();
        // today's messages — should NOT be counted
        ChatMessage::factory()->count(5)->create([
            'session_id' => $session->id,
            'role' => 'user',
            'created_at' => Carbon::today(),
        ]);

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $this->target->toDateString(),
            'total_messages' => 0,
        ]);
    }

    public function test_uses_yesterday_by_default(): void
    {
        Redis::shouldReceive('del')->once();

        $this->runJob();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => Carbon::yesterday()->toDateString(),
        ]);
    }

    public function test_accepts_custom_date(): void
    {
        Redis::shouldReceive('del')->once();

        $custom = Carbon::today()->subDays(3);
        $job = new DailyUsageStatsAggregatorJob($custom);
        $job->handle();

        $this->assertDatabaseHas('daily_usage_stats', [
            'date' => $custom->toDateString(),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function runJob(): void
    {
        $job = new DailyUsageStatsAggregatorJob($this->target);
        $job->handle();
    }
}
