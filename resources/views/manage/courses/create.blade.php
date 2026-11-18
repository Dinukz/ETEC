<x-layouts.app title="New Course" header="New Course">
    <div class="mb-6">
        <a href="{{ route('manage.courses.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to courses
        </a>
    </div>

    <div class="mx-auto max-w-3xl">
        <div class="card p-6 lg:p-8">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Create a course</h2>
            <p class="mt-1 mb-6 text-sm text-slate-500 dark:text-slate-400">You'll add modules and lessons on the next step.</p>

            <form method="POST" action="{{ route('manage.courses.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                @include('manage.courses._form', ['course' => null])
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('manage.courses.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Create Course</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
