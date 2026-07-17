@extends('layouts.admin')

@section('title', 'Налаштування — AI Чат Адмін')
@section('page-title', 'Налаштування')

@section('content')

<form method="POST" action="{{ route('admin.settings.update') }}"
      x-data="{
          tab: new URLSearchParams(location.search).get('tab') || 'chat',
          setTab(t) {
              this.tab = t;
              const url = new URL(location.href);
              url.searchParams.set('tab', t);
              history.replaceState(null, '', url);
          }
      }">
    @csrf
    @method('PUT')

    <div class="flex flex-col xl:flex-row gap-5">

        {{-- ── Tab navigation ──────────────────────────────────────────────── --}}
        <nav class="xl:w-52 shrink-0">
            <div class="admin-card p-2 flex xl:flex-col gap-1 overflow-x-auto xl:overflow-x-visible">

                @foreach ([
                    ['key' => 'chat',          'label' => 'Чат і промпти',    'icon' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z'],
                    ['key' => 'llm',           'label' => 'Моделі ШІ',        'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z'],
                    ['key' => 'limits',        'label' => 'Ліміти і бюджет',  'icon' => 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                    ['key' => 'notifications', 'label' => 'Сповіщення',       'icon' => 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0'],
                    ['key' => 'privacy',       'label' => 'Приватність',      'icon' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z'],
                ] as $item)
                    <button type="button"
                            @click="setTab('{{ $item['key'] }}')"
                            :class="tab === '{{ $item['key'] }}'
                                ? 'bg-sky-500/15 text-sky-300 border-sky-500/20'
                                : 'text-slate-400 hover:text-white hover:bg-white/5 border-transparent'"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap border transition-colors w-full text-left">
                        <svg class="w-[17px] h-[17px] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                        </svg>
                        <span>{{ $item['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </nav>

        {{-- ── Tab panels ──────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0">

            {{-- Validation errors banner --}}
            @if ($errors->any())
                <div class="mb-5 admin-card p-4 border border-red-500/20 bg-red-500/[0.03]">
                    <div class="flex gap-3">
                        <svg class="w-5 h-5 text-red-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-red-300 mb-1">Виправте помилки перед збереженням</p>
                            <ul class="text-xs text-red-400 space-y-0.5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ════ TAB: chat ════════════════════════════════════════════════ --}}
            <div x-show="tab === 'chat'" x-cloak>
                <div class="space-y-5">

                    {{-- System prompt --}}
                    <div class="admin-card p-5"
                         x-data="{ count: {{ mb_strlen(old('systemPrompt', $chat->systemPrompt)) }} }">
                        <div class="flex items-center justify-between mb-3">
                            <label for="systemPrompt" class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                Системний промпт
                            </label>
                            <span class="text-[10px] text-slate-600" x-text="count + ' симв.'"></span>
                        </div>
                        <textarea id="systemPrompt" name="systemPrompt" rows="8"
                                  @input="count = $event.target.value.length"
                                  class="input-field w-full rounded-xl px-4 py-3 text-sm text-white font-mono leading-relaxed resize-y @error('systemPrompt') ring-1 ring-red-500/50 @enderror"
                        >{{ old('systemPrompt', $chat->systemPrompt) }}</textarea>
                        @error('systemPrompt')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        <p class="mt-2 text-[10px] text-slate-600">Описує роль, тематику та поведінку асистента. Змінюється без передеплою.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        {{-- Greeting --}}
                        <div class="admin-card p-5">
                            <label for="greetingMessage" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Вітальне повідомлення</label>
                            <textarea id="greetingMessage" name="greetingMessage" rows="3"
                                      class="input-field w-full rounded-xl px-4 py-3 text-sm text-white resize-none @error('greetingMessage') ring-1 ring-red-500/50 @enderror"
                            >{{ old('greetingMessage', $chat->greetingMessage) }}</textarea>
                            @error('greetingMessage')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        </div>

                        {{-- Degraded mode --}}
                        <div class="admin-card p-5">
                            <label for="degradedModeMessage" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Повідомлення деградованого режиму</label>
                            <textarea id="degradedModeMessage" name="degradedModeMessage" rows="3"
                                      class="input-field w-full rounded-xl px-4 py-3 text-sm text-white resize-none @error('degradedModeMessage') ring-1 ring-red-500/50 @enderror"
                            >{{ old('degradedModeMessage', $chat->degradedModeMessage) }}</textarea>
                            @error('degradedModeMessage')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    {{-- Consent text --}}
                    <div class="admin-card p-5">
                        <label for="consentText" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Текст згоди (Privacy)</label>
                        <textarea id="consentText" name="consentText" rows="3"
                                  class="input-field w-full rounded-xl px-4 py-3 text-sm text-white resize-none @error('consentText') ring-1 ring-red-500/50 @enderror"
                        >{{ old('consentText', $chat->consentText) }}</textarea>
                        @error('consentText')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        <p class="mt-2 text-[10px] text-slate-600">Показується користувачу при першому відкритті чату.</p>
                    </div>

                    {{-- Policy URL --}}
                    <div class="admin-card p-5">
                        <label for="policyUrl" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Посилання на політику конфіденційності</label>
                        <input type="text" id="policyUrl" name="policyUrl"
                               value="{{ old('policyUrl', $chat->policyUrl) }}"
                               class="input-field w-full rounded-xl px-4 py-3 text-sm text-white @error('policyUrl') ring-1 ring-red-500/50 @enderror"
                        >
                        @error('policyUrl')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        <p class="mt-2 text-[10px] text-slate-600">Повертається разом із текстом згоди у відповіді /session.</p>
                    </div>

                    {{-- Summarization prompt --}}
                    <div class="admin-card p-5">
                        <label for="summarizationPrompt" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Промпт суммаризації</label>
                        <textarea id="summarizationPrompt" name="summarizationPrompt" rows="5"
                                  class="input-field w-full rounded-xl px-4 py-3 text-sm text-white font-mono resize-y @error('summarizationPrompt') ring-1 ring-red-500/50 @enderror"
                        >{{ old('summarizationPrompt', $chat->summarizationPrompt) }}</textarea>
                        @error('summarizationPrompt')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>

                    {{-- Numeric params --}}
                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Параметри сесії і контексту</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label for="contextWindowSize" class="block text-xs text-slate-500 mb-1.5">Вікно контексту (повід.)</label>
                                <input id="contextWindowSize" type="number" name="contextWindowSize"
                                       value="{{ old('contextWindowSize', $chat->contextWindowSize) }}"
                                       min="5" max="50"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('contextWindowSize') ring-1 ring-red-500/50 @enderror">
                                @error('contextWindowSize')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">5–50 повідомлень</p>
                            </div>
                            <div>
                                <label for="summaryThreshold" class="block text-xs text-slate-500 mb-1.5">Поріг суммаризації (повід.)</label>
                                <input id="summaryThreshold" type="number" name="summaryThreshold"
                                       value="{{ old('summaryThreshold', $chat->summaryThreshold) }}"
                                       min="10" max="200"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('summaryThreshold') ring-1 ring-red-500/50 @enderror">
                                @error('summaryThreshold')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">10–200 повідомлень</p>
                            </div>
                            <div>
                                <label for="sessionTtlMinutes" class="block text-xs text-slate-500 mb-1.5">TTL сесії (хвилини)</label>
                                <input id="sessionTtlMinutes" type="number" name="sessionTtlMinutes"
                                       value="{{ old('sessionTtlMinutes', $chat->sessionTtlMinutes) }}"
                                       min="5" max="1440"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('sessionTtlMinutes') ring-1 ring-red-500/50 @enderror">
                                @error('sessionTtlMinutes')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">5–1440 хвилин</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ════ TAB: llm ═════════════════════════════════════════════════ --}}
            <div x-show="tab === 'llm'" x-cloak>
                <div class="space-y-5">

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Моделі OpenAI</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="primaryModel" class="block text-xs text-slate-500 mb-1.5">Основна модель <span class="text-red-400">*</span></label>
                                <input id="primaryModel" type="text" name="primaryModel"
                                       value="{{ old('primaryModel', $llm->primaryModel) }}"
                                       placeholder="gpt-4o-mini"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('primaryModel') ring-1 ring-red-500/50 @enderror">
                                @error('primaryModel')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="fallbackModel" class="block text-xs text-slate-500 mb-1.5">Резервна модель <span class="text-red-400">*</span></label>
                                <input id="fallbackModel" type="text" name="fallbackModel"
                                       value="{{ old('fallbackModel', $llm->fallbackModel) }}"
                                       placeholder="gpt-3.5-turbo"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('fallbackModel') ring-1 ring-red-500/50 @enderror">
                                @error('fallbackModel')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <p class="mt-3 text-[10px] text-slate-600">Резервна модель активується при недоступності основної (Circuit Breaker).</p>
                    </div>

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Ембединги</h3>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label for="embeddingProvider" class="block text-xs text-slate-500 mb-1.5">Провайдер</label>
                                <select id="embeddingProvider" name="embeddingProvider"
                                        class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('embeddingProvider') ring-1 ring-red-500/50 @enderror">
                                    <option value="local"  @selected(old('embeddingProvider', $llm->embeddingProvider) === 'local')>Local (vector-api)</option>
                                    <option value="openai" @selected(old('embeddingProvider', $llm->embeddingProvider) === 'openai')>OpenAI</option>
                                </select>
                                @error('embeddingProvider')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="embeddingModel" class="block text-xs text-slate-500 mb-1.5">Модель</label>
                                <input id="embeddingModel" type="text" name="embeddingModel"
                                       value="{{ old('embeddingModel', $llm->embeddingModel) }}"
                                       placeholder="text-embedding-3-small"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('embeddingModel') ring-1 ring-red-500/50 @enderror">
                                @error('embeddingModel')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="embeddingDimensions" class="block text-xs text-slate-500 mb-1.5">Розмірність вектора</label>
                                <input id="embeddingDimensions" type="number" name="embeddingDimensions"
                                       value="{{ old('embeddingDimensions', $llm->embeddingDimensions) }}"
                                       min="64" max="4096"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('embeddingDimensions') ring-1 ring-red-500/50 @enderror">
                                @error('embeddingDimensions')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="mt-4 p-3 rounded-lg bg-amber-500/5 border border-amber-500/15">
                            <div class="flex gap-2">
                                <svg class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                                </svg>
                                <p class="text-xs text-amber-400/80">Зміна моделі або розмірності ембедингів потребує повної переіндексації каталогу та бази знань (<code class="font-mono text-amber-300">php artisan chat:catalog-reindex</code>).</p>
                            </div>
                        </div>
                    </div>

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Контекст</h3>
                        <div class="max-w-xs">
                            <label for="maxContextTokens" class="block text-xs text-slate-500 mb-1.5">Максимум токенів контексту</label>
                            <input id="maxContextTokens" type="number" name="maxContextTokens"
                                   value="{{ old('maxContextTokens', $llm->maxContextTokens) }}"
                                   min="512" max="128000"
                                   class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('maxContextTokens') ring-1 ring-red-500/50 @enderror">
                            @error('maxContextTokens')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            <p class="mt-1 text-[10px] text-slate-600">512–128000 токенів</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ════ TAB: limits ══════════════════════════════════════════════ --}}
            <div x-show="tab === 'limits'" x-cloak>
                <div class="space-y-5">

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Денний бюджет</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md">
                            <div>
                                <label for="dailyBudgetUsd" class="block text-xs text-slate-500 mb-1.5">Бюджет (USD/день)</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm">$</span>
                                    <input id="dailyBudgetUsd" type="number" name="dailyBudgetUsd" step="0.01"
                                           value="{{ old('dailyBudgetUsd', $rateLimit->dailyBudgetUsd) }}"
                                           min="0"
                                           class="input-field w-full rounded-lg pl-7 pr-3 py-2 text-sm text-white @error('dailyBudgetUsd') ring-1 ring-red-500/50 @enderror">
                                </div>
                                @error('dailyBudgetUsd')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">0 = без обмежень</p>
                            </div>
                            <div x-data="{ threshold: {{ old('budgetAlertThreshold', $rateLimit->budgetAlertThreshold) }} }">
                                <label for="budgetAlertThreshold" class="block text-xs text-slate-500 mb-1.5">
                                    Поріг сповіщення (<span class="text-sky-400" x-text="Math.round(threshold * 100) + '%'"></span>)
                                </label>
                                <input id="budgetAlertThreshold" type="range" name="budgetAlertThreshold"
                                       x-model="threshold"
                                       min="0.1" max="1.0" step="0.05"
                                       class="w-full mt-2 accent-sky-500">
                                @error('budgetAlertThreshold')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">При досягненні % бюджету надходить сповіщення</p>
                            </div>
                        </div>
                    </div>

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Rate Limiting (запитів/хв)</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label for="rateLimitSessionRpm" class="block text-xs text-slate-500 mb-1.5">На сесію</label>
                                <input id="rateLimitSessionRpm" type="number" name="rateLimitSessionRpm"
                                       value="{{ old('rateLimitSessionRpm', $rateLimit->rateLimitSessionRpm) }}"
                                       min="1" max="1000"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('rateLimitSessionRpm') ring-1 ring-red-500/50 @enderror">
                                @error('rateLimitSessionRpm')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">1–1000 RPM</p>
                            </div>
                            <div>
                                <label for="rateLimitIpRpm" class="block text-xs text-slate-500 mb-1.5">На IP</label>
                                <input id="rateLimitIpRpm" type="number" name="rateLimitIpRpm"
                                       value="{{ old('rateLimitIpRpm', $rateLimit->rateLimitIpRpm) }}"
                                       min="1" max="10000"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('rateLimitIpRpm') ring-1 ring-red-500/50 @enderror">
                                @error('rateLimitIpRpm')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">1–10000 RPM</p>
                            </div>
                            <div>
                                <label for="rateLimitGlobalRpm" class="block text-xs text-slate-500 mb-1.5">Глобальний</label>
                                <input id="rateLimitGlobalRpm" type="number" name="rateLimitGlobalRpm"
                                       value="{{ old('rateLimitGlobalRpm', $rateLimit->rateLimitGlobalRpm) }}"
                                       min="1" max="100000"
                                       class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('rateLimitGlobalRpm') ring-1 ring-red-500/50 @enderror">
                                @error('rateLimitGlobalRpm')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">1–100000 RPM</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ════ TAB: notifications ═══════════════════════════════════════ --}}
            <div x-show="tab === 'notifications'" x-cloak>
                <div class="space-y-5">

                    {{-- Email --}}
                    <div class="admin-card p-5"
                         x-data="{ enabled: {{ old('leadEmailEnabled', $notifications->leadEmailEnabled ? '1' : '0') === '1' ? 'true' : 'false' }} }">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-sm font-semibold text-white">Email сповіщення</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Надсилати нові заявки на email</p>
                            </div>
                            <label class="cursor-pointer">
                                <input type="hidden" name="leadEmailEnabled" :value="enabled ? '1' : '0'">
                                <button type="button"
                                        @click="enabled = !enabled"
                                        :class="enabled ? 'bg-sky-600' : 'bg-slate-700'"
                                        class="relative inline-flex w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none">
                                    <span :class="enabled ? 'translate-x-5' : 'translate-x-0.5'"
                                          class="inline-block w-5 h-5 mt-0.5 bg-white rounded-full shadow-sm transition-transform duration-200"></span>
                                </button>
                            </label>
                        </div>
                        <div :class="enabled ? 'opacity-100' : 'opacity-40 pointer-events-none'" class="transition-opacity">
                            <label for="leadEmailRecipient" class="block text-xs text-slate-500 mb-1.5">Email отримувача</label>
                            <input id="leadEmailRecipient" type="email" name="leadEmailRecipient"
                                   value="{{ old('leadEmailRecipient', $notifications->leadEmailRecipient) }}"
                                   placeholder="admin@example.com"
                                   class="input-field w-full max-w-sm rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 @error('leadEmailRecipient') ring-1 ring-red-500/50 @enderror">
                            @error('leadEmailRecipient')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    {{-- Telegram --}}
                    <div class="admin-card p-5"
                         x-data="{ enabled: {{ old('leadTelegramEnabled', $notifications->leadTelegramEnabled ? '1' : '0') === '1' ? 'true' : 'false' }} }">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-sm font-semibold text-white">Telegram сповіщення</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Надсилати нові заявки в Telegram</p>
                            </div>
                            <label class="cursor-pointer">
                                <input type="hidden" name="leadTelegramEnabled" :value="enabled ? '1' : '0'">
                                <button type="button"
                                        @click="enabled = !enabled"
                                        :class="enabled ? 'bg-sky-600' : 'bg-slate-700'"
                                        class="relative inline-flex w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none">
                                    <span :class="enabled ? 'translate-x-5' : 'translate-x-0.5'"
                                          class="inline-block w-5 h-5 mt-0.5 bg-white rounded-full shadow-sm transition-transform duration-200"></span>
                                </button>
                            </label>
                        </div>
                        <div :class="enabled ? 'opacity-100' : 'opacity-40 pointer-events-none'" class="transition-opacity space-y-3">
                            <div>
                                <label for="leadTelegramChatId" class="block text-xs text-slate-500 mb-1.5">Chat ID</label>
                                <input id="leadTelegramChatId" type="text" name="leadTelegramChatId"
                                       value="{{ old('leadTelegramChatId', $notifications->leadTelegramChatId) }}"
                                       placeholder="-100123456789"
                                       class="input-field w-full max-w-sm rounded-lg px-3 py-2 text-sm text-white font-mono placeholder-slate-600 @error('leadTelegramChatId') ring-1 ring-red-500/50 @enderror">
                                @error('leadTelegramChatId')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="leadTelegramBotToken" class="block text-xs text-slate-500 mb-1.5">Bot Token</label>
                                <input id="leadTelegramBotToken" type="password" name="leadTelegramBotToken"
                                       value=""
                                       autocomplete="new-password"
                                       placeholder="Залиште порожнім, щоб не змінювати"
                                       class="input-field w-full max-w-sm rounded-lg px-3 py-2 text-sm text-white font-mono placeholder-slate-600 @error('leadTelegramBotToken') ring-1 ring-red-500/50 @enderror">
                                @error('leadTelegramBotToken')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                <p class="mt-1 text-[10px] text-slate-600">
                                    Зберігається в зашифрованому вигляді.
                                    @if (filled($notifications->leadTelegramBotToken))
                                        <span class="text-emerald-500">· токен збережено</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ════ TAB: privacy ═════════════════════════════════════════════ --}}
            <div x-show="tab === 'privacy'" x-cloak>
                <div class="space-y-5">

                    <div class="admin-card p-5">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Зберігання даних</h3>
                        <div class="max-w-xs">
                            <label for="dataRetentionDays" class="block text-xs text-slate-500 mb-1.5">Термін зберігання (днів)</label>
                            <input id="dataRetentionDays" type="number" name="dataRetentionDays"
                                   value="{{ old('dataRetentionDays', $privacy->dataRetentionDays) }}"
                                   min="1" max="3650"
                                   class="input-field w-full rounded-lg px-3 py-2 text-sm text-white @error('dataRetentionDays') ring-1 ring-red-500/50 @enderror">
                            @error('dataRetentionDays')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            <p class="mt-1 text-[10px] text-slate-600">1–3650 днів (10 років). Після закінчення терміну сесії та повідомлення видаляються або анонімізуються щоденним джобом.</p>
                        </div>
                    </div>

                    <div class="admin-card p-5 border border-sky-500/10 bg-sky-500/[0.03]">
                        <div class="flex gap-3">
                            <svg class="w-4 h-4 text-sky-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
                            </svg>
                            <div class="text-xs text-slate-500 space-y-1.5">
                                <p class="font-medium text-slate-400">GDPR / Право на забуття</p>
                                <p>Для видалення або анонімізації даних конкретного користувача запустіть artisan-команду:</p>
                                <code class="block mt-1 px-2.5 py-1.5 rounded-lg bg-slate-900 text-emerald-400 font-mono text-[11px]">php artisan gdpr:purge --session=&lt;session_id&gt;</code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Save button (sticky) ──────────────────────────────────────── --}}
            <div class="mt-5 flex items-center justify-between">
                <p class="text-xs text-slate-600">Зміни набувають чинності одразу після збереження.</p>
                <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-semibold transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.75V16.5L12 14.25 7.5 16.5V3.75m9 0H18A2.25 2.25 0 0 1 20.25 6v12A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6A2.25 2.25 0 0 1 6 3.75h1.5m9 0h-9"/>
                    </svg>
                    Зберегти налаштування
                </button>
            </div>

        </div>{{-- end panels --}}
    </div>{{-- end flex wrapper --}}

</form>

@endsection
