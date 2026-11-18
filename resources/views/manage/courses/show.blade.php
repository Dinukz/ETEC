<x-layouts.app title="Curriculum" header="Curriculum Builder">
    <div class="mb-6">
        <a href="{{ route('manage.courses.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to courses
        </a>
    </div>

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ $course->title }}</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">{{ $course->modules->count() }} modules · {{ $course->lessons->count() }} lessons · {{ ucfirst($course->status) }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('manage.courses.edit', $course) }}" class="btn-secondary">Course Settings</a>
            <a href="{{ route('manage.lessons.create', $course) }}" class="btn-primary">Add Lesson</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @forelse ($course->modules as $module)
                <div class="card overflow-hidden" x-data="{ editing: false }">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 p-5 dark:border-slate-800">
                        <div x-show="!editing" class="min-w-0">
                            <h3 class="font-bold text-slate-900 dark:text-white">{{ $module->title }}</h3>
                            @if ($module->description)<p class="mt-0.5 truncate text-xs text-slate-400">{{ $module->description }}</p>@endif
                        </div>
                        <form x-show="editing" x-cloak method="POST" action="{{ route('manage.modules.update', $module) }}" class="flex flex-1 gap-2">
                            @csrf @method('PATCH')
                            <input name="title" value="{{ $module->title }}" required class="input py-1.5 text-sm">
                            <button class="btn-primary py-1.5 text-xs">Save</button>
                        </form>
                        <div class="flex shrink-0 items-center gap-2">
                            <button @click="editing = !editing" class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-text="editing ? 'Cancel' : 'Rename'"></button>
                            <form method="POST" action="{{ route('manage.modules.destroy', $module) }}" onsubmit="return confirm('Remove this module? Its lessons become unassigned.')">
                                @csrf @method('DELETE')
                                <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Remove</button>
                            </form>
                        </div>
                    </div>
                    @if ($module->lessons->isEmpty())
                        <p class="p-6 text-center text-sm text-slate-400">No lessons in this module yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($module->lessons as $lesson)
                                @include('manage.courses._lesson-row', ['lesson' => $lesson])
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <div class="card p-10 text-center text-sm text-slate-500 dark:text-slate-400">No modules yet — add one on the right, then add lessons into it.</div>
            @endforelse

            @if ($unassignedLessons->isNotEmpty())
                <div class="card overflow-hidden">
                    <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Unassigned lessons</h3></div>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($unassignedLessons as $lesson)
                            @include('manage.courses._lesson-row', ['lesson' => $lesson])
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div>
            <div class="card p-6">
                <h3 class="font-bold text-slate-900 dark:text-white">Add a module</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Modules group lessons into sections (e.g. "Week 1 — Basics").</p>
                <form method="POST" action="{{ route('manage.modules.store', $course) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="module_title" class="label">Title</label>
                        <input id="module_title" name="title" type="text" required class="input" placeholder="Module title">
                    </div>
                    <div>
                        <label for="module_description" class="label">Description <span class="font-normal text-slate-400">(optional)</span></label>
                        <textarea id="module_description" name="description" rows="2" class="input"></textarea>
                    </div>
                    <button type="submit" class="btn-primary w-full">Add Module</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
