@extends('layouts.admin')

@section('title', 'Дашборд — AI Чат Адмін')
@section('page-title', 'Дашборд')

@section('header-actions')
    <span class="text-xs text-slate-500">Оновлено: {{ now()->format('d.m.Y H:i') }}</span>
@endsection

@section('content')

{{-- ── Top stat tiles ─────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

    <div class="stat-tile">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider">Сесії сьогодні</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ number_format($todaySessions) }}</p>
                <p class="mt-1 text-xs text-slate-500">за тиждень: {{ number_format($weekSessions) }}</p>
            </div>
            <div class="p-2 rounded-xl bg-sky-500/10">
                <svg class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-tile">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider">Повідомлень сьогодні</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ number_format($todayMessages) }}</p>
                <p class="mt-1 text-xs text-slate-500">за тиждень: {{ number_format($weekMessages) }}</p>
            </div>
            <div class="p-2 rounded-xl bg-violet-500/10">
                <svg class="w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-tile">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider">Витрати сьогодні</p>
                <p class="mt-2 text-2xl font-bold text-white">${{ number_format($todayCostUsd, 4) }}</p>
                <p class="mt-1 text-xs text-slate-500">за тиждень: ${{ number_format($weekCost, 4) }}</p>
            </div>
            <div class="p-2 rounded-xl bg-amber-500/10">
                <svg class="w-5 h-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-tile">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-xs text-slate-500 uppercase tracking-wider">Заявки сьогодні</p>
                <p class="mt-2 text-2xl font-bold text-white">{{ number_format($todayLeads) }}</p>
                <p class="mt-1 text-xs text-slate-500">за тиждень: {{ number_format($weekLeads) }}</p>
            </div>
            <div class="p-2 rounded-xl bg-emerald-500/10">
                <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                </svg>
            </div>
        </div>
    </div>
</div>

{{-- ── Row 1: Messages + Cost line charts ──────────────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">

    {{-- Messages per day --}}
    <div class="admin-card p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-white">Повідомлення за 7 днів</h3>
                <p class="text-xs text-slate-500 mt-0.5">Кількість запитів користувачів</p>
            </div>
            <span class="badge badge-new">7 днів</span>
        </div>
        <div class="h-48">
            <canvas id="chart-messages"></canvas>
        </div>
    </div>

    {{-- Cost per day --}}
    <div class="admin-card p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-white">Витрати за 7 днів</h3>
                <p class="text-xs text-slate-500 mt-0.5">Щоденні витрати на OpenAI API, USD</p>
            </div>
            <span class="badge badge-contacted">USD</span>
        </div>
        <div class="h-48">
            <canvas id="chart-cost"></canvas>
        </div>
    </div>
</div>

{{-- ── Row 2: Latency bar + Escalations doughnut ───────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

    {{-- Avg latency bar (2/3 width) --}}
    <div class="admin-card p-5 xl:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-white">Середня латентність</h3>
                <p class="text-xs text-slate-500 mt-0.5">Час генерації відповіді, мс</p>
            </div>
            <span class="badge badge-spam">мс</span>
        </div>
        <div class="h-48">
            <canvas id="chart-latency"></canvas>
        </div>
    </div>

    {{-- Escalations doughnut (1/3 width) --}}
    <div class="admin-card p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-white">Ескалації</h3>
                <p class="text-xs text-slate-500 mt-0.5">Заявки / всього за тиждень</p>
            </div>
        </div>
        <div class="h-48 flex items-center justify-center">
            @if ($weekMessages > 0)
                <canvas id="chart-escalations"></canvas>
            @else
                <p class="text-sm text-slate-600">Немає даних</p>
            @endif
        </div>
        @if ($weekMessages > 0)
            <div class="mt-3 flex items-center justify-center gap-4 text-xs text-slate-500">
                <span class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-sky-500 inline-block"></span>
                    Звичайні ({{ $nonEscalatedTotal }})
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>
                    Заявки ({{ $escalatedTotal }})
                </span>
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const labels    = @js($labels);
    const messages  = @js($messagesData);
    const costs     = @js($costData);
    const latencies = @js($latencyData);
    const escalated    = {{ $escalatedTotal }};
    const nonEscalated = {{ $nonEscalatedTotal }};

    // ── Messages line chart ───────────────────────────────────────────────
    AdminCharts.make('chart-messages', AdminCharts.lineConfig(labels, [
        {
            label: 'Повідомлення',
            data: messages,
            borderColor: '#0ea5e9',
            backgroundColor: 'rgba(14,165,233,0.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointBackgroundColor: '#0ea5e9',
            fill: true,
            tension: 0.4,
        }
    ]));

    // ── Cost line chart ───────────────────────────────────────────────────
    AdminCharts.make('chart-cost', AdminCharts.lineConfig(labels, [
        {
            label: 'USD',
            data: costs,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointBackgroundColor: '#f59e0b',
            fill: true,
            tension: 0.4,
        }
    ], {
        scales: {
            x: { grid: { color: 'rgba(255,255,255,0.04)' } },
            y: {
                grid: { color: 'rgba(255,255,255,0.04)' },
                beginAtZero: true,
                ticks: { callback: v => '$' + v.toFixed(4) },
            },
        },
    }));

    // ── Latency bar chart ─────────────────────────────────────────────────
    AdminCharts.make('chart-latency', AdminCharts.barConfig(labels, [
        {
            label: 'мс',
            data: latencies,
            backgroundColor: 'rgba(139,92,246,0.6)',
            borderColor: '#8b5cf6',
            borderWidth: 1,
            borderRadius: 4,
        }
    ]));

    // ── Escalations doughnut ──────────────────────────────────────────────
    if (document.getElementById('chart-escalations')) {
        AdminCharts.make('chart-escalations', AdminCharts.doughnutConfig(
            ['Звичайні', 'Заявки'],
            [nonEscalated, escalated],
            ['rgba(14,165,233,0.7)', 'rgba(245,158,11,0.7)'],
        ));
    }
});
</script>
@endpush
