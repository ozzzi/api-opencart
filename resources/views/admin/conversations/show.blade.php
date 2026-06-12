@extends('layouts.admin')

@section('title', 'Діалог ' . mb_substr($conversation->id, 0, 8) . '… — AI Чат Адмін')
@section('page-title', 'Діалог')

@section('header-actions')
    <a href="{{ route('admin.conversations.index') }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Назад
    </a>
@endsection

@section('content')

<div class="grid grid-cols-1 xl:grid-cols-4 gap-5">

    {{-- ── Thread (3/4 width) ────────────────────────────────────────────── --}}
    <div class="xl:col-span-3 space-y-3">

        @forelse ($conversation->messages as $message)
            @php
                $isUser      = $message->role === 'user';
                $isAssistant = $message->role === 'assistant';
                $isTool      = $message->role === 'tool';
                $isSystem    = $message->role === 'system';
            @endphp

            <div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[85%] {{ $isSystem ? 'w-full max-w-full' : '' }}">

                    {{-- Role badge --}}
                    <div class="flex items-center gap-2 mb-1.5 {{ $isUser ? 'justify-end' : 'justify-start' }}">
                        @if ($isUser)
                            <span class="badge badge-new">Користувач</span>
                        @elseif ($isAssistant)
                            <span class="badge badge-closed">Асистент</span>
                        @elseif ($isTool)
                            <span class="badge badge-contacted">Tool</span>
                        @else
                            <span class="badge" style="background:rgba(100,116,139,0.15);color:#94a3b8">System</span>
                        @endif
                        <span class="text-[10px] text-slate-600">{{ $message->created_at->format('H:i:s') }}</span>
                    </div>

                    {{-- Message bubble --}}
                    <div @class([
                        'rounded-2xl px-4 py-3 text-sm leading-relaxed',
                        'bg-sky-600/20 border border-sky-500/20 text-slate-200' => $isUser,
                        'bg-slate-800/70 border border-white/[0.07] text-slate-200' => $isAssistant,
                        'bg-violet-500/10 border border-violet-500/15 text-slate-300 font-mono text-xs' => $isTool,
                        'bg-slate-900/50 border border-white/[0.04] text-slate-500 text-xs italic' => $isSystem,
                    ])>
                        {{-- Content --}}
                        @if ($message->content)
                            <p class="{{ $isTool || $isSystem ? '' : 'whitespace-pre-wrap' }}">{{ $message->content }}</p>
                        @endif

                        {{-- Tool calls (collapsible via Alpine) --}}
                        @if ($message->tool_calls)
                            <div x-data="{ open: false }" class="{{ $message->content ? 'mt-3 pt-3 border-t border-white/[0.08]' : '' }}">
                                <button @click="open = !open"
                                        class="flex items-center gap-1.5 text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                    <svg :class="open ? 'rotate-90' : ''"
                                         class="w-3.5 h-3.5 transition-transform"
                                         fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                                    </svg>
                                    <span x-text="open ? 'Приховати tool calls' : 'Показати tool calls ({{ count($message->tool_calls) }})'"></span>
                                </button>
                                <div x-show="open" x-cloak x-transition class="mt-2">
                                    <pre class="bg-black/40 rounded-lg p-3 text-xs text-violet-300 overflow-x-auto">{{ json_encode($message->tool_calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Metadata row --}}
                    @if ($message->model || $message->tokens_used || $message->latency_ms)
                        <div class="flex flex-wrap items-center gap-3 mt-1.5 {{ $isUser ? 'justify-end' : 'justify-start' }}">
                            @if ($message->model)
                                <span class="text-[10px] text-slate-600 font-mono">{{ $message->model }}</span>
                            @endif
                            @if ($message->tokens_used)
                                <span class="text-[10px] text-slate-600">
                                    <svg class="w-3 h-3 inline-block -mt-px" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/>
                                    </svg>
                                    {{ number_format($message->tokens_used) }} токенів
                                </span>
                            @endif
                            @if ($message->latency_ms)
                                <span class="text-[10px] text-slate-600">{{ number_format($message->latency_ms) }} мс</span>
                            @endif
                            @if ($message->fallback_used)
                                <span class="text-[10px] bg-amber-500/15 text-amber-400 px-1.5 py-0.5 rounded-full">fallback</span>
                            @endif
                        </div>
                    @endif

                </div>
            </div>
        @empty
            <div class="py-20 text-center admin-card">
                <p class="text-sm text-slate-500">Повідомлень немає</p>
            </div>
        @endforelse

    </div>

    {{-- ── Session info sidebar (1/4 width) ────────────────────────────── --}}
    <div class="space-y-4">

        {{-- Session metadata --}}
        <div class="admin-card p-5">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-4">Сесія</h3>
            <dl class="space-y-3">
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">ID</dt>
                    <dd class="mt-0.5 font-mono text-xs text-slate-400 break-all">{{ $conversation->id }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">IP</dt>
                    <dd class="mt-0.5 text-xs text-slate-400">{{ $conversation->ip_address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Мова</dt>
                    <dd class="mt-0.5 text-xs text-slate-400">
                        {{ $conversation->language === 'uk' ? '🇺🇦 Українська' : '🇷🇺 Російська' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Розпочато</dt>
                    <dd class="mt-0.5 text-xs text-slate-400">{{ $conversation->created_at->format('d.m.Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Остання активність</dt>
                    <dd class="mt-0.5 text-xs text-slate-400">{{ $conversation->last_activity_at->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Згода</dt>
                    <dd class="mt-0.5">
                        @if ($conversation->consent_accepted_at)
                            <span class="badge badge-closed">Так — {{ $conversation->consent_accepted_at->format('d.m.y H:i') }}</span>
                        @else
                            <span class="badge badge-spam">Не надано</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Message stats --}}
        <div class="admin-card p-5">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-4">Статистика</h3>
            @php
                $userMsgs      = $conversation->messages->where('role', 'user')->count();
                $assistantMsgs = $conversation->messages->where('role', 'assistant')->count();
                $toolMsgs      = $conversation->messages->where('role', 'tool')->count();
                $totalTokens   = $conversation->messages->sum('tokens_used');
                $avgLatency    = $conversation->messages->whereNotNull('latency_ms')->avg('latency_ms');
                $fallbackCount = $conversation->messages->where('fallback_used', true)->count();
            @endphp
            <dl class="space-y-3">
                <div class="flex justify-between items-center">
                    <dt class="text-xs text-slate-500">Запитів</dt>
                    <dd class="text-xs font-semibold text-white">{{ $userMsgs }}</dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-xs text-slate-500">Відповідей</dt>
                    <dd class="text-xs font-semibold text-white">{{ $assistantMsgs }}</dd>
                </div>
                @if ($toolMsgs)
                    <div class="flex justify-between items-center">
                        <dt class="text-xs text-slate-500">Tool calls</dt>
                        <dd class="text-xs font-semibold text-violet-400">{{ $toolMsgs }}</dd>
                    </div>
                @endif
                @if ($totalTokens)
                    <div class="flex justify-between items-center">
                        <dt class="text-xs text-slate-500">Токенів всього</dt>
                        <dd class="text-xs font-semibold text-white">{{ number_format($totalTokens) }}</dd>
                    </div>
                @endif
                @if ($avgLatency)
                    <div class="flex justify-between items-center">
                        <dt class="text-xs text-slate-500">Сер. латентність</dt>
                        <dd class="text-xs font-semibold text-white">{{ number_format($avgLatency) }} мс</dd>
                    </div>
                @endif
                @if ($fallbackCount)
                    <div class="flex justify-between items-center">
                        <dt class="text-xs text-slate-500">Fallback</dt>
                        <dd><span class="badge badge-contacted">{{ $fallbackCount }}</span></dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Lead (if exists) --}}
        @if ($conversation->lead)
            @php $lead = $conversation->lead; @endphp
            <div class="admin-card p-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-4">Заявка</h3>
                <dl class="space-y-3">
                    @if ($lead->name)
                        <div>
                            <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Ім'я</dt>
                            <dd class="mt-0.5 text-xs text-slate-300">{{ $lead->name }}</dd>
                        </div>
                    @endif
                    @if ($lead->phone)
                        <div>
                            <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Телефон</dt>
                            <dd class="mt-0.5 text-xs text-slate-300">{{ $lead->phone }}</dd>
                        </div>
                    @endif
                    @if ($lead->email)
                        <div>
                            <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Email</dt>
                            <dd class="mt-0.5 text-xs text-slate-300">{{ $lead->email }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-[10px] text-slate-600 uppercase tracking-wide">Статус</dt>
                        <dd class="mt-1">
                            @php
                                $statusBadge = match ($lead->status) {
                                    'new'       => 'badge-new',
                                    'contacted' => 'badge-contacted',
                                    'closed'    => 'badge-closed',
                                    'spam'      => 'badge-spam',
                                    default     => 'badge-new',
                                };
                                $statusLabel = match ($lead->status) {
                                    'new'       => 'Нова',
                                    'contacted' => 'Оброблено',
                                    'closed'    => 'Закрито',
                                    'spam'      => 'Спам',
                                    default     => $lead->status,
                                };
                            @endphp
                            <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </dd>
                    </div>
                    <div>
                        <a href="{{ route('admin.leads.index') }}"
                           class="text-xs text-sky-400 hover:text-sky-300 transition-colors">
                            Переглянути всі заявки →
                        </a>
                    </div>
                </dl>
            </div>
        @endif

        {{-- Context summary (if exists) --}}
        @if ($conversation->context_summary)
            <div class="admin-card p-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Конспект</h3>
                <p class="text-xs text-slate-400 leading-relaxed">{{ $conversation->context_summary }}</p>
            </div>
        @endif

    </div>
</div>

@endsection
