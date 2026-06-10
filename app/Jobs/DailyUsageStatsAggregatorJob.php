<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\DailyUsageStat;
use App\Models\Bot\Lead;
use App\Models\Bot\LlmApiCall;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Redis;

#[Tries(3)]
#[Backoff([5, 30, 60])]
final class DailyUsageStatsAggregatorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?Carbon $date = null,
    ) {
    }

    public function handle(): void
    {
        $date = ($this->date ?? Carbon::yesterday())->copy()->startOfDay();

        $totalMessages = ChatMessage::whereDate('created_at', $date)
            ->where('role', 'user')
            ->count();

        $totalSessions = ChatMessage::whereDate('created_at', $date)
            ->distinct('session_id')
            ->count('session_id');

        $costRow = LlmApiCall::whereDate('created_at', $date)
            ->where('success', true)
            ->selectRaw('SUM(cost_usd) as total_cost, AVG(latency_ms) as avg_latency')
            ->first();

        $totalCost = (float) ($costRow?->total_cost ?? 0.0);
        $avgLatency = (int) round((float) ($costRow?->avg_latency ?? 0.0));

        $escalations = Lead::whereDate('created_at', $date)
            ->where('status', '!=', 'spam')
            ->count();

        DailyUsageStat::updateOrCreate(
            ['date' => $date->toDateString()],
            [
                'total_sessions' => $totalSessions,
                'total_messages' => $totalMessages,
                'total_cost_usd' => $totalCost,
                'avg_latency_ms' => $avgLatency,
                'escalations' => $escalations,
            ],
        );

        Redis::del("chat:daily_cost:{$date->toDateString()}");
    }
}
