@php
    $course = $enrollment->course;
    $progress = (int) round($enrollment->progress_percentage ?? 0);
    $isCompleted = $enrollment->status === 'completed';
@endphp

<div class="card group flex flex-col overflow-hidden transition-all duration-300 hover:shadow-lg">
    <div class="relative aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
        @if ($course && $course->thumbnail)
            <img src="{{ $course->thumbnail_url }}" alt="{{ $course->title }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 text-white/80"><svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"/></svg></div>
        @endif
        @if ($isCompleted)
            <span class="badge absolute right-3 top-3 bg-emerald-500 text-white shadow">✓ Completed</span>
        @endif
    </div>
    <div class="flex flex-1 flex-col p-5">
        @if ($course && $course->category)
            <span class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ $course->category }}</span>
        @endif
        <h4 class="mt-1 line-clamp-2 font-bold text-slate-900 dark:text-white">{{ $course->title ?? 'Course' }}</h4>
        @if ($course && $course->relationLoaded('lecturer') && $course->lecturer)
            <p class="mt-1 text-xs text-slate-400">by {{ $course->lecturer->name }}</p>
        @endif

        <div class="mt-4 flex-1">
            <div class="mb-1.5 flex items-center justify-between text-xs">
                <span class="font-medium text-slate-500 dark:text-slate-400">Progress</span>
                <span class="font-bold text-slate-700 dark:text-slate-200">{{ $progress }}%</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full {{ $isCompleted ? 'bg-emerald-500' : 'bg-brand-500' }} transition-all" style="width: {{ $progress }}%"></div>
            </div>
        </div>

        @if ($course && in_array($enrollment->status, ['active', 'completed'], true))
            <a href="{{ route('student.learn', $course) }}" class="btn-primary mt-5 w-full py-2.5 text-sm">
                {{ $isCompleted ? 'Review course' : 'Continue learning' }}
            </a>
        @elseif ($course)
            <a href="{{ route('courses.show', $course->slug) }}" class="btn-secondary mt-5 w-full py-2.5 text-sm">
                Pending payment verification
            </a>
        @endif
    </div>
</div>
