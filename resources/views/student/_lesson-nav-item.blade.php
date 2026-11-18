<li>
    <a href="{{ route('student.learn.lesson', [$course, $item]) }}" class="flex items-center gap-3 px-5 py-3 text-sm transition {{ $item->id === $lesson->id ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800/50' }}">
        @if ($completedIds->contains($item->id))
            <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9" stroke-width="1.6"/></svg>
        @else
            <svg class="h-4 w-4 shrink-0 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/></svg>
        @endif
        <span class="truncate">{{ $item->title }}</span>
        @if ($item->duration_minutes)<span class="ml-auto shrink-0 text-xs text-slate-400">{{ $item->duration_minutes }}m</span>@endif
    </a>
</li>
