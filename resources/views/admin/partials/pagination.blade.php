@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-4">
        <p class="text-xs text-slate-500">
            Показано {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} з {{ $paginator->total() }}
        </p>
        <div class="flex items-center gap-1">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 rounded-lg text-xs text-slate-600 cursor-not-allowed">← Назад</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}"
                   class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white hover:bg-white/5 transition-colors">← Назад</a>
            @endif

            {{-- Page numbers --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 py-1.5 text-xs text-slate-600">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-600/20 text-sky-400">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}"
                               class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white hover:bg-white/5 transition-colors">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}"
                   class="px-3 py-1.5 rounded-lg text-xs text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Далі →</a>
            @else
                <span class="px-3 py-1.5 rounded-lg text-xs text-slate-600 cursor-not-allowed">Далі →</span>
            @endif
        </div>
    </nav>
@endif
