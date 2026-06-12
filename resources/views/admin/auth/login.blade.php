<!DOCTYPE html>
<html lang="uk" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід — AI Чат Адмін</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: radial-gradient(ellipse at top, #1e3a5f 0%, #0f172a 50%, #020617 100%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .input-field {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.2s ease;
        }
        .input-field:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(14, 165, 233, 0.6);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
            outline: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(14, 165, 233, 0.4);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        .dot {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        .dot:nth-child(2) { animation-delay: 0.2s; }
        .dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }
    </style>
</head>
<body class="h-full gradient-bg flex items-center justify-center p-4">

    <div class="w-full max-w-md">

        {{-- Logo & title --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl glass-card mb-5 shadow-xl">
                <svg class="w-8 h-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/>
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white tracking-tight">AI Чат Адмін</h1>
            <p class="mt-1.5 text-sm text-slate-400">Панель керування чат-ботом</p>
            <div class="flex items-center justify-center gap-1 mt-3">
                <span class="dot w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                <span class="dot w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                <span class="dot w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
            </div>
        </div>

        {{-- Card --}}
        <div class="glass-card rounded-2xl p-8 shadow-2xl">

            {{-- Error alert --}}
            @if ($errors->any())
                <div class="mb-6 flex items-start gap-3 bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
                    <svg class="w-5 h-5 text-red-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    <div>
                        @foreach ($errors->all() as $error)
                            <p class="text-sm text-red-300">{{ $error }}</p>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Session status --}}
            @if (session('status'))
                <div class="mb-6 flex items-center gap-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl px-4 py-3">
                    <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                    <p class="text-sm text-emerald-300">{{ session('status') }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.post') }}" class="space-y-5">
                @csrf

                {{-- Email --}}
                <div class="space-y-1.5">
                    <label for="email" class="block text-sm font-medium text-slate-300">
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                        class="input-field w-full rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm"
                        placeholder="admin@example.com"
                    >
                </div>

                {{-- Password --}}
                <div class="space-y-1.5">
                    <label for="password" class="block text-sm font-medium text-slate-300">
                        Пароль
                    </label>
                    <div class="relative">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="input-field w-full rounded-xl px-4 py-3 text-white placeholder-slate-500 text-sm pr-11"
                            placeholder="••••••••"
                        >
                        <button
                            type="button"
                            onclick="togglePassword()"
                            class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-200 transition-colors"
                        >
                            <svg id="eye-icon" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Remember me --}}
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2.5 cursor-pointer group">
                        <input
                            type="checkbox"
                            name="remember"
                            class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-sky-500 focus:ring-sky-500 focus:ring-offset-0"
                        >
                        <span class="text-sm text-slate-400 group-hover:text-slate-300 transition-colors">
                            Запам'ятати мене
                        </span>
                    </label>
                </div>

                {{-- Submit --}}
                <button
                    type="submit"
                    class="btn-primary w-full rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg mt-2"
                >
                    Увійти
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-slate-600">
            AI Chat Admin Panel &copy; {{ date('Y') }}
        </p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
