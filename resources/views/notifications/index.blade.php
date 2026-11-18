<x-layouts.app title="Notifications" header="Notifications">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Notifications</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Payment updates, grades, service income, and system messages.</p>
        </div>
        <form method="POST" action="{{ route('notifications.readAll') }}">
            @csrf
            <button class="btn-secondary">Mark all read</button>
        </form>
    </div>

    <div class="card overflow-hidden">
        @if ($notifications->isEmpty())
            <p class="p-12 text-center text-sm text-slate-500 dark:text-slate-400">No notifications yet.</p>
        @else
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($notifications as $n)
                    <li class="{{ $n->read_at ? '' : 'bg-brand-50/50 dark:bg-brand-500/5' }}">
                        <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                            @csrf
                            <button type="submit" class="block w-full px-6 py-4 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $n->data['title'] ?? 'Notification' }}</span>
                                        <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">{{ $n->data['message'] ?? '' }}</span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @unless ($n->read_at)<span class="h-2 w-2 rounded-full bg-brand-500"></span>@endunless
                                        <span class="text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-8">{{ $notifications->links() }}</div>
</x-layouts.app>
