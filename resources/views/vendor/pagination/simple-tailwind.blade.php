@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex justify-center gap-3">
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
    </nav>
@endif
