<x-layouts.app title="Zoom Meetings" header="Zoom Meetings">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Zoom Meetings</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Schedule live sessions — enrolled students see the join link.</p>
        </div>
        <a href="{{ route('manage.zoom.create') }}" class="btn-primary">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Schedule Meeting
        </a>
    </div>

    <form method="GET" action="{{ route('manage.zoom.index') }}" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <select name="course_id" class="input lg:w-64" onchange="this.form.submit()">
            <option value="">All courses</option>
            @foreach ($courses as $c)
                <option value="{{ $c->id }}" @selected(request('course_id') == $c->id)>{{ $c->title }}</option>
            @endforeach
        </select>
        <select name="status" class="input lg:w-40" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['scheduled', 'live', 'ended', 'cancelled'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($meetings->isEmpty())
        <div class="card p-12 text-center">
            <h3 class="font-bold text-slate-900 dark:text-white">No meetings scheduled</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Schedule your first live session.</p>
            <a href="{{ route('manage.zoom.create') }}" class="btn-primary mt-6">Schedule Meeting</a>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="px-6 py-4 font-semibold">Meeting</th>
                            <th class="px-6 py-4 font-semibold">Course</th>
                            <th class="px-6 py-4 font-semibold">Starts</th>
                            <th class="px-6 py-4 font-semibold">Duration</th>
                            <th class="px-6 py-4 font-semibold">Status</th>
                            <th class="px-6 py-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($meetings as $m)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 dark:text-white">{{ $m->title }}</div>
                                    @if ($m->meeting_id)<div class="text-xs text-slate-400">ID: {{ $m->meeting_id }}@if($m->passcode) · Pass: {{ $m->passcode }}@endif</div>@endif
                                </td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($m->course->title ?? '—', 26) }}</td>
                                <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ optional($m->start_at)->format('M d, Y H:i') }}</td>
                                <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ $m->duration_minutes }} min</td>
                                <td class="px-6 py-4">
                                    @php $ms = ['scheduled' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400', 'live' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'ended' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400', 'cancelled' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'][$m->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                                    <span class="badge {{ $ms }}">{{ ucfirst($m->status) }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ $m->start_url ?: $m->join_url }}" target="_blank" class="badge bg-emerald-50 text-emerald-600 transition hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400">Start</a>
                                        <a href="{{ route('manage.zoom.edit', $m) }}" class="badge bg-slate-100 text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Edit</a>
                                        @if ($m->status !== 'cancelled')
                                            <form method="POST" action="{{ route('manage.zoom.destroy', $m) }}" onsubmit="return confirm('Cancel this meeting?')">
                                                @csrf @method('DELETE')
                                                <button class="badge bg-rose-50 text-rose-600 transition hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">Cancel</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8">{{ $meetings->links() }}</div>
    @endif
</x-layouts.app>
