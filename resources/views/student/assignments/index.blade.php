<x-layouts.app title="Assignments" header="My Assignments">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Assignments</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Assignments from your enrolled courses.</p>
    </div>

    @if ($assignments->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No assignments yet — check back after your lecturer publishes one.</div>
    @else
        <div class="space-y-4">
            @foreach ($assignments as $a)
                @php $sub = $submissions[$a->id] ?? null; @endphp
                <a href="{{ route('student.assignments.show', $a) }}" class="card flex flex-wrap items-center justify-between gap-3 p-5 transition hover:shadow-md">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900 dark:text-white">{{ $a->title }}</div>
                        <div class="mt-0.5 text-xs text-slate-400">
                            {{ $a->course->title ?? '' }}
                            · Due {{ optional($a->due_date)->format('M d, Y H:i') ?? 'no deadline' }}
                            · {{ $a->total_marks }} marks
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (! $sub)
                            <span class="badge {{ $a->status === 'closed' ? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' }}">{{ $a->status === 'closed' ? 'Closed' : 'Not submitted' }}</span>
                        @elseif ($sub->status === 'graded')
                            <span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">Graded: {{ $sub->marks_obtained }}/{{ $a->total_marks }}</span>
                        @else
                            <span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ ucfirst($sub->status) }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-8">{{ $assignments->links() }}</div>
    @endif
</x-layouts.app>
