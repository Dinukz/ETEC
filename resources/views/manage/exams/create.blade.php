<x-layouts.app title="New Exam" header="New Exam">
    <div class="mb-6">
        <a href="{{ route('manage.exams.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to exams
        </a>
    </div>

    <div class="mx-auto max-w-3xl">
        <div class="card p-6 lg:p-8">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Create an exam</h2>
            <p class="mt-1 mb-6 text-sm text-slate-500 dark:text-slate-400">You'll add questions on the next step.</p>

            @if ($errors->any())
                <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                    <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('manage.exams.store') }}" class="space-y-5">
                @csrf
                @include('manage.exams._form', ['exam' => null])
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('manage.exams.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Create & Add Questions</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
