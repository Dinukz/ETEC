<li class="flex items-center gap-3 p-4">
    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </span>
    <div class="min-w-0 flex-1">
        <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $lesson->title }}</div>
        <div class="text-xs text-slate-400">
            {{ $lesson->duration_minutes ? $lesson->duration_minutes.' min' : 'No duration' }}
            · {{ ucfirst($lesson->status) }}
            @if ($lesson->is_preview) · Free preview @endif
        </div>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <a href="{{ route('manage.lessons.edit', $lesson) }}" class="badge bg-slate-100 text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Edit</a>
        <form method="POST" action="{{ route('manage.lessons.destroy', $lesson) }}" onsubmit="return confirm('Delete this lesson?')">
            @csrf @method('DELETE')
            <button class="badge bg-rose-50 text-rose-600 transition hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
        </form>
    </div>
</li>
