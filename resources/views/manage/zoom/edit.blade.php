<x-layouts.app title="Edit Meeting" header="Edit Meeting">
    <div class="mb-6">
        <a href="{{ route('manage.zoom.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to meetings
        </a>
    </div>

    <div class="mx-auto max-w-3xl">
        <div class="card p-6 lg:p-8">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Edit "{{ $meeting->title }}"</h2>
            <form method="POST" action="{{ route('manage.zoom.update', $meeting) }}" class="mt-6 space-y-5">
                @csrf @method('PATCH')
                @include('manage.zoom._form')
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('manage.zoom.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Save Meeting</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
