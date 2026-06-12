@extends('layouts.admin')

@section('title', 'Нова стаття — AI Чат Адмін')
@section('page-title', 'Нова стаття')

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

<form method="POST" action="{{ route('admin.kb.store') }}" x-data="{ charCount: {{ old('content') ? mb_strlen(old('content')) : 0 }} }">
    @csrf

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
                    value="{{ old('title') }}"
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
                    placeholder="Введіть зміст статті. Підтримується Markdown…"
                    required
                    @input="charCount = $event.target.value.length"
                    class="input-field w-full rounded-xl px-4 py-3 text-white text-sm leading-relaxed placeholder-slate-600 resize-y font-mono @error('content') ring-1 ring-red-500/50 @enderror"
                >{{ old('content') }}</textarea>
                @error('content')
                    <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-[10px] text-slate-600">Підтримується Markdown. Текст буде розбитий на чанки та проіндексований в OpenSearch після збереження.</p>
            </div>
        </div>

        {{-- ── Sidebar (1/3) ───────────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Publish card --}}
            <div class="admin-card p-5">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Публікація</h3>

                <label class="flex items-center gap-3 cursor-pointer group mb-5">
                    <div class="relative" x-data="{ checked: {{ old('is_published', '1') === '1' ? 'true' : 'false' }} }">
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Зберегти статтю
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
                        <option value="uk" @selected(old('lang', 'uk') === 'uk')>🇺🇦 Українська</option>
                        <option value="ru" @selected(old('lang') === 'ru')>🇷🇺 Російська</option>
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
                        value="{{ old('category') }}"
                        placeholder="Наприклад: Доставка"
                        maxlength="100"
                        class="input-field w-full rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 @error('category') ring-1 ring-red-500/50 @enderror"
                    >
                    @error('category')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-[10px] text-slate-600">Використовується для фільтрації в пошуку</p>
                </div>
            </div>

            {{-- Tips --}}
            <div class="admin-card p-5 border border-sky-500/10 bg-sky-500/[0.03]">
                <div class="flex gap-3">
                    <svg class="w-4 h-4 text-sky-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
                    </svg>
                    <div class="text-xs text-slate-500 space-y-1.5">
                        <p class="font-medium text-slate-400">Поради для RAG</p>
                        <p>Чіткі заголовки покращують пошук по семантиці.</p>
                        <p>Розбивайте великі теми на окремі статті.</p>
                        <p>Вказуйте мову відповідно до мови вмісту.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@endsection
