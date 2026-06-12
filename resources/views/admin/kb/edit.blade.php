@extends('layouts.admin')

@section('title', 'Редагування — ' . $kb->title . ' — AI Чат Адмін')
@section('page-title', 'Редагування статті')

@section('header-actions')
    <a href="{{ route('admin.kb.index') }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        База знань
    </a>
@endsection

@section('content')

<form method="POST" action="{{ route('admin.kb.update', $kb) }}"
      x-data="{ charCount: {{ mb_strlen(old('content', $kb->content)) }} }">
    @csrf
    @method('PUT')

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        {{-- ── Main content (2/3) ──────────────────────────────────────────── --}}
        <div class="xl:col-span-2 space-y-5">

            {{-- Title --}}
            <div class="admin-card p-5">
                <label for="title" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">
                    Заголовок <span class="text-red-400">*</span>
                </label>
                <input
                    id="title"
                    type="text"
                    name="title"
                    value="{{ old('title', $kb->title) }}"
                    placeholder="Введіть заголовок статті…"
                    maxlength="500"
                    required
                    class="input-field w-full rounded-xl px-4 py-3 text-white text-base font-medium placeholder-slate-600 @error('title') ring-1 ring-red-500/50 @enderror"
                >
                @error('title')
                    <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Content --}}
            <div class="admin-card p-5">
                <div class="flex items-center justify-between mb-3">
                    <label for="content" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        Зміст <span class="text-red-400">*</span>
                    </label>
                    <span class="text-[10px] text-slate-600" x-text="charCount.toLocaleString() + ' симв.'"></span>
                </div>
                <textarea
                    id="content"
                    name="content"
                    rows="18"
                    placeholder="Введіть зміст статті…"
                    required
                    @input="charCount = $event.target.value.length"
                    class="input-field w-full rounded-xl px-4 py-3 text-white text-sm leading-relaxed placeholder-slate-600 resize-y font-mono @error('content') ring-1 ring-red-500/50 @enderror"
                >{{ old('content', $kb->content) }}</textarea>
                @error('content')
                    <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-[10px] text-slate-600">Після збереження стаття буде поставлена в чергу переіндексації в OpenSearch.</p>
            </div>
        </div>

        {{-- ── Sidebar (1/3) ───────────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Publish card --}}
            <div class="admin-card p-5">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Публікація</h3>

                <label class="flex items-center gap-3 cursor-pointer group mb-5">
                    <div class="relative" x-data="{ checked: {{ old('is_published', $kb->is_published ? '1' : '0') === '1' ? 'true' : 'false' }} }">
                        <input type="hidden" name="is_published" :value="checked ? '1' : '0'">
                        <button type="button"
                                @click="checked = !checked"
                                :class="checked ? 'bg-sky-600' : 'bg-slate-700'"
                                class="relative inline-flex w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none">
                            <span :class="checked ? 'translate-x-5' : 'translate-x-0.5'"
                                  class="inline-block w-5 h-5 mt-0.5 bg-white rounded-full shadow-sm transition-transform duration-200"></span>
                        </button>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-white">Опубліковано</p>
                        <p class="text-xs text-slate-500">Стаття доступна для RAG-пошуку</p>
                    </div>
                </label>

                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-semibold transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.75V16.5L12 14.25 7.5 16.5V3.75m9 0H18A2.25 2.25 0 0 1 20.25 6v12A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6A2.25 2.25 0 0 1 6 3.75h1.5m9 0h-9"/>
                    </svg>
                    Зберегти зміни
                </button>
            </div>

            {{-- Meta --}}
            <div class="admin-card p-5 space-y-4">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Метадані</h3>

                <div>
                    <label for="lang" class="block text-xs text-slate-500 mb-1.5">
                        Мова <span class="text-red-400">*</span>
                    </label>
                    <select id="lang" name="lang" required
                            class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('lang') ring-1 ring-red-500/50 @enderror">
                        <option value="uk" @selected(old('lang', $kb->lang) === 'uk')>🇺🇦 Українська</option>
                        <option value="ru" @selected(old('lang', $kb->lang) === 'ru')>🇷🇺 Російська</option>
                    </select>
                    @error('lang')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="category" class="block text-xs text-slate-500 mb-1.5">Категорія</label>
                    <input
                        id="category"
                        type="text"
                        name="category"
                        value="{{ old('category', $kb->category) }}"
                        placeholder="Наприклад: Доставка"
                        maxlength="100"
                        class="input-field w-full rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 @error('category') ring-1 ring-red-500/50 @enderror"
                    >
                    @error('category')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Article info --}}
            <div class="admin-card p-5 space-y-3">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Інформація</h3>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-600">ID</span>
                        <span class="font-mono text-xs text-slate-400">#{{ $kb->id }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-600">Створено</span>
                        <span class="text-xs text-slate-400">{{ $kb->created_at->format('d.m.Y H:i') }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-600">Оновлено</span>
                        <span class="text-xs text-slate-400">{{ $kb->updated_at->format('d.m.Y H:i') }}</span>
                    </div>
                </div>

                <div class="pt-2 border-t border-white/[0.06]">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-600">OpenSearch</span>
                        @if ($kb->opensearch_indexed_at)
                            <span class="badge badge-contacted text-[10px]">
                                {{ $kb->opensearch_indexed_at->format('d.m.y H:i') }}
                            </span>
                        @else
                            <span class="badge text-[10px]" style="background:rgba(100,116,139,0.15);color:#64748b">Не проіндексовано</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Danger zone --}}
            <div class="admin-card p-5 border border-red-500/10">
                <h3 class="text-xs font-semibold text-red-400/70 uppercase tracking-wider mb-3">Небезпечна зона</h3>
                <p class="text-xs text-slate-600 mb-3">Видалення статті призведе до видалення всіх її чанків з OpenSearch.</p>
                <button
                    type="button"
                    x-data
                    @click="if (confirm('Видалити статтю «{{ addslashes($kb->title) }}»? Цю дію неможливо скасувати.')) $refs.dangerDeleteForm.submit()"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 text-xs font-medium transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    Видалити статтю
                </button>
                <form x-ref="dangerDeleteForm"
                      method="POST"
                      action="{{ route('admin.kb.destroy', $kb) }}"
                      class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            </div>
        </div>
    </div>
</form>

@endsection
