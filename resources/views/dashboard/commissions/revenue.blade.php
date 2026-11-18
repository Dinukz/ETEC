<x-layouts.app title="Company Revenue" header="Company Revenue">
    <div class="mb-6">
        <a href="{{ route('commissions.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to service income
        </a>
    </div>

    <div class="mb-6 grid gap-5 sm:grid-cols-3">
        <div class="card bg-gradient-to-br from-brand-700 to-brand-950 p-6 text-white sm:col-span-1">
            <p class="text-sm text-white/70">Total company revenue</p>
            <p class="mt-2 text-3xl font-extrabold">LKR {{ number_format($total) }}</p>
        </div>
        <x-stat-card label="Course Payments" :value="'LKR ' . number_format($bySource['course_payment'] ?? 0)" color="emerald" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13" />
        <x-stat-card label="Service Group Overflow" :value="'LKR ' . number_format($bySource['group_overflow'] ?? 0)" color="amber" icon="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800"><h3 class="font-bold text-slate-900 dark:text-white">Revenue transactions</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Source</th>
                        <th class="px-6 py-4 font-semibold">Course</th>
                        <th class="px-6 py-4 font-semibold">Description</th>
                        <th class="px-6 py-4 font-semibold">Amount</th>
                        <th class="px-6 py-4 font-semibold">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($revenues as $r)
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4"><span class="badge bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ \Illuminate\Support\Str::headline($r->source_type) }}</span></td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-300">{{ \Illuminate\Support\Str::limit($r->course->title ?? '—', 24) }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($r->description, 40) }}</td>
                            <td class="px-6 py-4 font-bold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($r->amount) }}</td>
                            <td class="px-6 py-4 text-slate-500 dark:text-slate-400">{{ optional($r->recorded_at)->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">No revenue recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-8">{{ $revenues->links() }}</div>
</x-layouts.app>
