<x-layouts.app title="Message" header="Contact Message">
    <div class="mb-6">
        <a href="{{ route('cms.messages.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to messages
        </a>
    </div>

    <div class="mx-auto max-w-2xl space-y-6">
        <div class="card p-6 lg:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ $message->subject ?? '(No subject)' }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        From <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $message->name }}</span>
                        &lt;<a href="mailto:{{ $message->email }}" class="text-brand-600 hover:underline dark:text-brand-400">{{ $message->email }}</a>&gt;
                        @if ($message->phone) · {{ $message->phone }} @endif
                    </p>
                    <p class="mt-0.5 text-xs text-slate-400">Received {{ $message->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
            <div class="mt-5 whitespace-pre-line rounded-xl bg-slate-50 p-5 text-sm text-slate-700 dark:bg-slate-800/50 dark:text-slate-200">{{ $message->message }}</div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                <form method="POST" action="{{ route('cms.messages.status', $message) }}" class="flex items-center gap-2">
                    @csrf @method('PATCH')
                    <select name="status" class="input py-2 text-sm">
                        @foreach (['new', 'read', 'replied'] as $s)
                            <option value="{{ $s }}" @selected($message->status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <button class="btn-secondary">Update Status</button>
                </form>
                <div class="flex gap-2">
                    <a href="mailto:{{ $message->email }}?subject=Re: {{ rawurlencode($message->subject ?? 'Your enquiry') }}" class="btn-primary">Reply via Email</a>
                    <form method="POST" action="{{ route('cms.messages.destroy', $message) }}" onsubmit="return confirm('Delete this message?')">
                        @csrf @method('DELETE')
                        <button class="btn-secondary text-rose-600 dark:text-rose-400">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
