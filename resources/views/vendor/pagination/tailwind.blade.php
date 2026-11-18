@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between">
        {{-- Mobile --}}
        <div class="flex flex-1 justify-between gap-3 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn-secondary cursor-default opacity-50">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn-secondary">Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn-secondary">Next</a>
            @else
                <span class="btn-secondary cursor-default opacity-50">Next</span>
            @endif
        </div>

        {{-- Desktop --}}
        <div class="hidden w-full items-center justify-between sm:flex">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Showing <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $paginator->firstItem() }}</span>
                to <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $paginator->lastItem() }}</span>
                of <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $paginator->total() }}</span> results
            </p>

            <span class="inline-flex items-center gap-1">
                {{-- Prev --}}
                @if ($paginator->onFirstPage())
                    <span class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-300 dark:border-slate-800 dark:text-slate-700"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg></span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg></a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="grid h-10 w-10 place-items-center text-slate-400">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="grid h-10 min-w-[2.5rem] place-items-center rounded-xl bg-brand-600 px-2 text-sm font-semibold text-white">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="grid h-10 min-w-[2.5rem] place-items-center rounded-xl border border-slate-200 px-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></a>
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-300 dark:border-slate-800 dark:text-slate-700"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></span>
                @endif
            </span>
        </div>
    </nav>
@endif
