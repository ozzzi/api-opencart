@extends('layouts.admin')

@section('title', 'Заявки — AI Чат Адмін')
@section('page-title', 'Заявки')

@section('header-actions')
    <span class="text-xs text-slate-500">{{ $leads->total() }} всього</span>
@endsection

@section('content')

{{-- ── Filters ──────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.leads.index') }}"
      class="admin-card p-4 mb-5 flex flex-wrap gap-3 items-end">

    <div class="flex-1 min-w-40">
        <label class="block text-xs text-slate-500 mb-1.5">Пошук</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Ім'я, телефон або email…"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600">
    </div>

    <div class="min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Статус</label>
        <select name="status" class="input-field w-full rounded-lg px-3 py-2 text-sm text-white">
            <option value="">Всі</option>
            <option value="new"       @selected(request('status') === 'new')>Новий</option>
            <option value="contacted" @selected(request('status') === 'contacted')>Зв'язались</option>
            <option value="closed"    @selected(request('status') === 'closed')>Закрито</option>
            <option value="spam"      @selected(request('status') === 'spam')>Спам</option>
        </select>
    </div>

    <div class="min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Від</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white [color-scheme:dark]">
    </div>

    <div class="min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">До</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white [color-scheme:dark]">
    </div>

    <div class="flex gap-2">
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors">
            Застосувати
        </button>
        @if (request()->hasAny(['search', 'status', 'date_from', 'date_to']))
            <a href="{{ route('admin.leads.index') }}"
               class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm font-medium transition-colors">
                Скинути
            </a>
        @endif
    </div>
</form>

{{-- ── Status count pills ───────────────────────────────────────────────── --}}
@php
    $statusCounts = \App\Models\Bot\Lead::query()
        ->selectRaw('status, count(*) as total')
        ->groupBy('status')
        ->pluck('total', 'status');
@endphp
<div class="flex flex-wrap gap-2 mb-4">
    @foreach (['new' => ['label' => 'Нові', 'class' => 'badge-new'], 'contacted' => ['label' => "Зв'язались", 'class' => 'badge-contacted'], 'closed' => ['label' => 'Закриті', 'class' => 'badge-closed'], 'spam' => ['label' => 'Спам', 'class' => 'badge-spam']] as $s => $meta)
        @if ($statusCounts->has($s))
            <a href="{{ route('admin.leads.index', ['status' => $s]) }}"
               class="badge {{ request('status') === $s ? $meta['class'] : '' }} hover:opacity-80 transition-opacity">
                {{ $meta['label'] }} {{ $statusCounts[$s] }}
            </a>
        @endif
    @endforeach
</div>

{{-- ── Table ────────────────────────────────────────────────────────────── --}}
<div class="admin-card overflow-hidden">
    @if ($leads->isEmpty())
        <div class="py-20 text-center">
            <svg class="w-10 h-10 mx-auto mb-3 text-slate-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
            </svg>
            <p class="text-sm text-slate-500">Заявок не знайдено</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Контакт</th>
                        <th class="hidden md:table-cell">Повідомлення</th>
                        <th class="hidden lg:table-cell">Товари</th>
                        <th>Статус</th>
                        <th class="hidden xl:table-cell">Сповіщено</th>
                        <th class="hidden sm:table-cell">Дата</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($leads as $lead)
                        <tr x-data="{ status: '{{ $lead->status }}', saving: false }">

                            {{-- Contact --}}
                            <td>
                                <div>
                                    @if ($lead->name)
                                        <p class="font-medium text-slate-200 text-sm">{{ $lead->name }}</p>
                                    @else
                                        <p class="text-xs text-slate-600 italic">Без імені</p>
                                    @endif
                                    <div class="mt-1 space-y-0.5">
                                        @if ($lead->phone)
                                            <p class="font-mono text-xs text-slate-400">{{ $lead->phone }}</p>
                                        @endif
                                        @if ($lead->email)
                                            <p class="text-xs text-slate-500">{{ $lead->email }}</p>
                                        @endif
                                    </div>
                                    @if ($lead->session_id)
                                        <a href="{{ route('admin.conversations.show', $lead->session_id) }}"
                                           class="inline-flex items-center gap-1 mt-1.5 text-[10px] text-sky-500 hover:text-sky-400 transition-colors">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                                            </svg>
                                            Діалог
                                        </a>
                                    @endif
                                </div>
                            </td>

                            {{-- Message --}}
                            <td class="hidden md:table-cell max-w-xs">
                                @if ($lead->message)
                                    <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed">{{ $lead->message }}</p>
                                @else
                                    <span class="text-xs text-slate-700">—</span>
                                @endif
                            </td>

                            {{-- Products from OpenCart --}}
                            <td class="hidden lg:table-cell">
                                @if (!empty($lead->product_ids))
                                    <div class="space-y-1">
                                        @foreach ($lead->product_ids as $pid)
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-1.5 h-1.5 rounded-full bg-sky-500/50 shrink-0"></span>
                                                <span class="text-xs text-slate-400 truncate max-w-[160px]">
                                                    {{ $productNames[$pid] ?? '#'.$pid }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-700">—</span>
                                @endif
                            </td>

                            {{-- Status (inline change) --}}
                            <td>
                                <form method="POST"
                                      action="{{ route('admin.leads.update', $lead) }}"
                                      x-ref="statusForm"
                                      class="inline">
                                    @csrf
                                    @method('PUT')
                                    <select
                                        name="status"
                                        x-model="status"
                                        @change="saving = true; $nextTick(() => $refs.statusForm.submit())"
                                        :disabled="saving"
                                        class="input-field rounded-lg px-2 py-1.5 text-xs font-medium cursor-pointer disabled:opacity-50
                                               transition-colors
                                               focus:outline-none focus:ring-1 focus:ring-sky-500/50"
                                        :class="{
                                            'text-sky-300 bg-sky-500/10':   status === 'new',
                                            'text-amber-300 bg-amber-500/10': status === 'contacted',
                                            'text-emerald-300 bg-emerald-500/10': status === 'closed',
                                            'text-red-300 bg-red-500/10':   status === 'spam',
                                        }"
                                    >
                                        <option value="new">Новий</option>
                                        <option value="contacted">Зв'язались</option>
                                        <option value="closed">Закрито</option>
                                        <option value="spam">Спам</option>
                                    </select>
                                </form>
                            </td>

                            {{-- Notified --}}
                            <td class="hidden xl:table-cell">
                                @if ($lead->notified_at)
                                    <span class="badge badge-closed text-[10px]">
                                        {{ $lead->notified_at->format('d.m.y H:i') }}
                                    </span>
                                @else
                                    <span class="text-xs text-slate-700">—</span>
                                @endif
                            </td>

                            {{-- Date --}}
                            <td class="hidden sm:table-cell text-xs text-slate-500 whitespace-nowrap">
                                <p>{{ $lead->created_at->format('d.m.y') }}</p>
                                <p class="text-slate-700">{{ $lead->created_at->format('H:i') }}</p>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($leads->hasPages())
            <div class="px-4 py-3 border-t border-white/[0.06]">
                {{ $leads->links('admin.partials.pagination') }}
            </div>
        @endif
    @endif
</div>

@endsection
