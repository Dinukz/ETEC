<x-layouts.app title="New FAQ" header="New FAQ">
    <div class="mb-6">
        <a href="{{ route('cms.faqs.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to FAQs
        </a>
    </div>
    <div class="mx-auto max-w-2xl">
        <div class="card p-6 lg:p-8">
            <form method="POST" action="{{ route('cms.faqs.store') }}" class="space-y-5">
                @csrf
                @include('cms.faqs._form', ['faq' => null])
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <a href="{{ route('cms.faqs.index') }}" class="btn-secondary">Cancel</a>
                    <button type="submit" class="btn-primary">Add FAQ</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
