<x-layouts.app title="Testimonials" header="Website Content — Testimonials">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">Testimonials</h2>
            <p class="mt-1 text-slate-500 dark:text-slate-400">Approved testimonials appear on the public website.</p>
        </div>
        <a href="{{ route('cms.testimonials.create') }}" class="btn-primary">New Testimonial</a>
    </div>

    <form method="GET" action="{{ route('cms.testimonials.index') }}" class="card mb-6 flex gap-3 p-4">
        <select name="status" class="input sm:w-48" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['pending', 'approved', 'rejected'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($testimonials->isEmpty())
        <div class="card p-12 text-center text-sm text-slate-500 dark:text-slate-400">No testimonials yet.</div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($testimonials as $t)
                <div class="card flex flex-col p-5">
                    <div class="flex items-center justify-between">
                        <div class="flex text-amber-400">
                            @for ($i = 1; $i <= 5; $i++)
                                <svg class="h-4 w-4 {{ $i <= $t->rating ? '' : 'opacity-25' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.367 2.446a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.539 1.118l-3.366-2.445a1 1 0 00-1.176 0l-3.366 2.445c-.783.57-1.838-.196-1.539-1.118l1.287-3.957a1 1 0 00-.364-1.118L2.062 9.385c-.783-.57-.38-1.81.589-1.81h4.161a1 1 0 00.95-.69l1.287-3.958z"/></svg>
                            @endfor
                        </div>
                        @php $ts = ['approved' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'pending' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'rejected' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'][$t->status] ?? 'bg-slate-100 text-slate-500'; @endphp
                        <span class="badge {{ $ts }}">{{ ucfirst($t->status) }}</span>
                    </div>
                    <p class="mt-3 flex-1 text-sm text-slate-600 dark:text-slate-300">"{{ \Illuminate\Support\Str::limit($t->content, 140) }}"</p>
                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ $t->name }}</div>
                        <div class="text-xs text-slate-400">{{ $t->designation ?? '' }}</div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <a href="{{ route('cms.testimonials.edit', $t) }}" class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">Edit</a>
                        <form method="POST" action="{{ route('cms.testimonials.destroy', $t) }}" onsubmit="return confirm('Delete this testimonial?')">
                            @csrf @method('DELETE')
                            <button class="badge bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Delete</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-8">{{ $testimonials->links() }}</div>
    @endif
</x-layouts.app>
