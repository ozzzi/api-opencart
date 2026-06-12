<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use App\Models\Bot\DailyUsageStat;
use App\Models\Bot\Lead;
use App\Services\Chat\Contracts\CostTrackerInterface;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly CostTrackerInterface $costTracker,
    ) {
    }

    public function index(): View
    {
        $today = Carbon::today();
        $sevenDaysAgo = $today->copy()->subDays(6);

        // ── 7-day history from daily_usage_stats ─────────────────────────
        $history = DailyUsageStat::query()
            ->whereBetween('date', [$sevenDaysAgo->toDateString(), $today->copy()->subDay()->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        // ── Today live from DB + Redis ────────────────────────────────────
        $todaySessions  = ChatSession::whereDate('created_at', $today)->count();
        $todayMessages  = ChatMessage::whereDate('created_at', $today)->whereIn('role', ['user'])->count();
        $todayCostUsd   = $this->costTracker->getDailyCost($today);
        $todayLeads     = Lead::whereDate('created_at', $today)->count();
        $todayLatencyMs = (int) ChatMessage::whereDate('created_at', $today)
            ->whereNotNull('latency_ms')
            ->avg('latency_ms');

        // ── Build 7-day chart series ──────────────────────────────────────
        $labels          = [];
        $messagesData    = [];
        $costData        = [];
        $latencyData     = [];
        $escalationsData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date    = $today->copy()->subDays($i);
            $dateKey = $date->toDateString();
            $label   = $date->format('d.m');

            $labels[] = $label;

            if ($i === 0) {
                // today — live values
                $messagesData[]    = $todayMessages;
                $costData[]        = round($todayCostUsd, 4);
                $latencyData[]     = $todayLatencyMs;
                $escalationsData[] = $todayLeads;
            } else {
                $row               = $history->get($dateKey);
                $messagesData[]    = $row?->total_messages ?? 0;
                $costData[]        = $row ? (float) $row->total_cost_usd : 0.0;
                $latencyData[]     = $row?->avg_latency_ms ?? 0;
                $escalationsData[] = $row?->escalations ?? 0;
            }
        }

        // ── Summary tiles (sum over 7 days) ───────────────────────────────
        $weekMessages = array_sum($messagesData);
        $weekCost     = array_sum($costData);
        $weekLeads    = array_sum($escalationsData);
        $weekSessions = $history->sum('total_sessions') + $todaySessions;

        // ── Doughnut: escalated vs non-escalated messages ─────────────────
        $escalatedTotal    = array_sum($escalationsData);
        $nonEscalatedTotal = max(0, $weekMessages - $escalatedTotal);

        return view('admin.dashboard.index', compact(
            'labels',
            'messagesData',
            'costData',
            'latencyData',
            'escalationsData',
            'todaySessions',
            'todayMessages',
            'todayCostUsd',
            'todayLeads',
            'weekMessages',
            'weekCost',
            'weekLeads',
            'weekSessions',
            'escalatedTotal',
            'nonEscalatedTotal',
        ));
    }
}
