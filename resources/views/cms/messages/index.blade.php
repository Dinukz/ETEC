<x-layouts.app title="Contact Messages" header="Contact Messages">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Contact Messages</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">{{ $counts['new'] }} new of {{ $counts['total'] }} total messages from the website contact form.</p>
    </div>

    <form method="GET" action="{{ route('cms.messages.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, email, subject..." class="input pl-11">
        </div>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['new', 'read', 'replied'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Search</button>
    </form>

    @if ($messages->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No messages found.</div>
    @else
        <div class="card overflow-hidden">
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($messages as $message)
                    <li>
                        <a href="{{ route('cms.messages.show', $message) }}" class="flex flex-wrap items-center justify-between gap-3 p-5 transition hover:bg-slate-50 dark:hover:bg-slate-800/40 {{ $message->status === 'new' ? 'bg-brand-50/40 dark:bg-brand-500/5' : '' }}">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900 dark:text-white">{{ $message->subject ?? '(No subject)' }}</div>
                                <div class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $message->name }} · {{ $message->email }}</div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                @php $ms = ['new' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400', 'read' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400', 'replied' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'][$message->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                                <span class="badge {{ $ms }}">{{ ucfirst($message->status) }}</span>
                                <span class="text-xs text-slate-400">{{ $message->created_at->diffForHumans() }}</span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="mt-8">{{ $messages->links() }}</div>
    @endif
</x-layouts.app>
