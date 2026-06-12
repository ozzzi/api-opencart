@extends('layouts.admin')

@section('title', 'Діалоги — AI Чат Адмін')
@section('page-title', 'Діалоги')

@section('header-actions')
    <span class="text-xs text-slate-500">{{ $sessions->total() }} всього</span>
@endsection

@section('content')

{{-- ── Filters ──────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.conversations.index') }}"
      class="admin-card p-4 mb-5 flex flex-wrap gap-3 items-end">

    <div class="flex-1 min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Мова</label>
        <select name="language"
                class="input-field w-full rounded-lg px-3 py-2 text-sm text-white">
            <option value="">Всі</option>
            <option value="uk" @selected(request('language') === 'uk')>🇺🇦 Українська</option>
            <option value="ru" @selected(request('language') === 'ru')>🇷🇺 Російська</option>
        </select>
    </div>

    <div class="flex-1 min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Від</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white [color-scheme:dark]">
    </div>

    <div class="flex-1 min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">До</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white [color-scheme:dark]">
    </div>

    <div class="flex gap-2">
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors">
            Застосувати
        </button>
        @if (request()->hasAny(['language', 'date_from', 'date_to']))
            <a href="{{ route('admin.conversations.index') }}"
               class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm font-medium transition-colors">
                Скинути
            </a>
        @endif
    </div>
</form>

{{-- ── Table ────────────────────────────────────────────────────────────── --}}
<div class="admin-card overflow-hidden">
    @if ($sessions->isEmpty())
        <div class="py-20 text-center">
            <svg class="w-10 h-10 mx-auto mb-3 text-slate-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
            </svg>
            <p class="text-sm text-slate-500">Діалогів не знайдено</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Сесія</th>
                        <th class="hidden sm:table-cell">IP / Мова</th>
                        <th class="hidden md:table-cell">Повідомлень</th>
                        <th class="hidden lg:table-cell">Остання активність</th>
                        <th class="hidden xl:table-cell">Згода</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-slate-800 border border-white/[0.07] flex items-center justify-center shrink-0">
                                        <span class="text-xs font-semibold text-slate-400">
                                            {{ mb_strtoupper($session->language) }}
                                        </span>
                                    </div>
                                    <div>
                                        <p class="font-mono text-xs text-slate-300">
                                            {{ mb_substr($session->id, 0, 8) }}…
                                        </p>
                                        <p class="text-xs text-slate-600 mt-0.5">
                                            {{ $session->created_at->format('d.m.y H:i') }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden sm:table-cell">
                                <p class="font-mono text-xs text-slate-400">{{ $session->ip_address ?? '—' }}</p>
                                <p class="text-xs text-slate-600 mt-0.5">
                                    {{ $session->language === 'uk' ? '🇺🇦 uk' : '🇷🇺 ru' }}
                                </p>
                            </td>
                            <td class="hidden md:table-cell">
                                <span class="badge badge-new">{{ $session->messages_count }}</span>
                            </td>
                            <td class="hidden lg:table-cell text-slate-400 text-xs">
                                {{ $session->last_activity_at->diffForHumans() }}
                            </td>
                            <td class="hidden xl:table-cell">
                                @if ($session->consent_accepted_at)
                                    <span class="badge badge-closed">Так</span>
                                @else
                                    <span class="badge badge-spam">Ні</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.conversations.show', $session) }}"
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 text-xs font-medium transition-colors">
                                    Перегляд
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($sessions->hasPages())
            <div class="px-4 py-3 border-t border-white/[0.06]">
                {{ $sessions->links('admin.partials.pagination') }}
            </div>
        @endif
    @endif
</div>

@endsection
