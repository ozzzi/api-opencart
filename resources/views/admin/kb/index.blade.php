@extends('layouts.admin')

@section('title', 'База знань — AI Чат Адмін')
@section('page-title', 'База знань')

@section('header-actions')
    <a href="{{ route('admin.kb.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Нова стаття
    </a>
@endsection

@section('content')

{{-- ── Filters ──────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.kb.index') }}"
      class="admin-card p-4 mb-5 flex flex-wrap gap-3 items-end">

    <div class="flex-1 min-w-40">
        <label class="block text-xs text-slate-500 mb-1.5">Пошук</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Заголовок або зміст…"
               class="input-field w-full rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600">
    </div>

    <div class="min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Мова</label>
        <select name="lang" class="input-field w-full rounded-lg px-3 py-2 text-sm text-white">
            <option value="">Всі</option>
            <option value="uk" @selected(request('lang') === 'uk')>🇺🇦 Українська</option>
            <option value="ru" @selected(request('lang') === 'ru')>🇷🇺 Російська</option>
        </select>
    </div>

    @if ($categories->isNotEmpty())
        <div class="min-w-40">
            <label class="block text-xs text-slate-500 mb-1.5">Категорія</label>
            <select name="category" class="input-field w-full rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Всі</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="min-w-36">
        <label class="block text-xs text-slate-500 mb-1.5">Статус</label>
        <select name="status" class="input-field w-full rounded-lg px-3 py-2 text-sm text-white">
            <option value="">Всі</option>
            <option value="published" @selected(request('status') === 'published')>Опубліковано</option>
            <option value="draft" @selected(request('status') === 'draft')>Чернетка</option>
        </select>
    </div>

    <div class="flex gap-2">
        <button type="submit"
                class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors">
            Застосувати
        </button>
        @if (request()->hasAny(['search', 'lang', 'category', 'status']))
            <a href="{{ route('admin.kb.index') }}"
               class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm font-medium transition-colors">
                Скинути
            </a>
        @endif
    </div>
</form>

{{-- ── Stats row ────────────────────────────────────────────────────────── --}}
<div class="flex items-center gap-3 mb-4 text-xs text-slate-500">
    <span>{{ $articles->total() }} {{ trans_choice('статей|статті|статей', $articles->total()) }}</span>
    @if (request()->hasAny(['search', 'lang', 'category', 'status']))
        <span class="text-sky-500">· застосовано фільтри</span>
    @endif
</div>

{{-- ── Table ────────────────────────────────────────────────────────────── --}}
<div class="admin-card overflow-hidden">
    @if ($articles->isEmpty())
        <div class="py-20 text-center">
            <svg class="w-10 h-10 mx-auto mb-3 text-slate-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>
            </svg>
            <p class="text-sm text-slate-500 mb-4">Статей не знайдено</p>
            <a href="{{ route('admin.kb.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-sky-600/20 hover:bg-sky-600/30 text-sky-400 text-sm font-medium transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Додати першу статтю
            </a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Стаття</th>
                        <th class="hidden sm:table-cell">Мова / Категорія</th>
                        <th class="hidden md:table-cell">Статус</th>
                        <th class="hidden lg:table-cell">Індексація</th>
                        <th class="hidden xl:table-cell">Оновлено</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($articles as $article)
                        <tr>
                            <td>
                                <div>
                                    <p class="font-medium text-slate-200 text-sm leading-snug line-clamp-1">
                                        {{ $article->title }}
                                    </p>
                                    <p class="text-xs text-slate-600 mt-0.5 line-clamp-1">
                                        {{ mb_substr(strip_tags($article->content), 0, 80) }}…
                                    </p>
                                </div>
                            </td>
                            <td class="hidden sm:table-cell">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs text-slate-400">
                                        {{ $article->lang === 'uk' ? '🇺🇦 uk' : '🇷🇺 ru' }}
                                    </span>
                                    @if ($article->category)
                                        <span class="text-xs text-slate-600">{{ $article->category }}</span>
                                    @else
                                        <span class="text-xs text-slate-700">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                @if ($article->is_published)
                                    <span class="badge badge-closed">Опубліковано</span>
                                @else
                                    <span class="badge badge-spam">Чернетка</span>
                                @endif
                            </td>
                            <td class="hidden lg:table-cell">
                                @if ($article->opensearch_indexed_at)
                                    <div>
                                        <span class="badge badge-contacted">Проіндексовано</span>
                                        <p class="text-[10px] text-slate-600 mt-1">
                                            {{ $article->opensearch_indexed_at->format('d.m.y H:i') }}
                                        </p>
                                    </div>
                                @else
                                    <span class="badge" style="background:rgba(100,116,139,0.15);color:#64748b">Очікує</span>
                                @endif
                            </td>
                            <td class="hidden xl:table-cell text-xs text-slate-500">
                                {{ $article->updated_at->diffForHumans() }}
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('admin.kb.edit', $article) }}"
                                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 text-xs font-medium transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/>
                                        </svg>
                                        Редагувати
                                    </a>
                                    <button
                                        x-data
                                        @click="if (confirm('Видалити статтю «{{ addslashes($article->title) }}»?')) $refs.deleteForm{{ $article->id }}.submit()"
                                        class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 text-xs font-medium transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                        </svg>
                                    </button>
                                    <form x-ref="deleteForm{{ $article->id }}"
                                          method="POST"
                                          action="{{ route('admin.kb.destroy', $article) }}"
                                          class="hidden">
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($articles->hasPages())
            <div class="px-4 py-3 border-t border-white/[0.06]">
                {{ $articles->links('admin.partials.pagination') }}
            </div>
        @endif
    @endif
</div>

@endsection
