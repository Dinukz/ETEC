<x-layouts.app title="Account Suspended" header="Account Suspended">
    <div class="mx-auto max-w-2xl">
        <div class="card overflow-hidden border-rose-200 dark:border-rose-500/30">
            <div class="bg-rose-50 p-6 text-center dark:bg-rose-500/10">
                <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </span>
                <h2 class="mt-4 text-xl font-extrabold text-rose-700 dark:text-rose-300">Your consultant account is suspended</h2>
                <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">
                    You missed the weekly student registration quota for too many finance weeks in a row.
                    Registering students, viewing your groups, and Service Income are all locked until an
                    administrator reactivates your account.
                </p>
            </div>

            <div class="divide-y divide-slate-100 p-6 dark:divide-slate-800">
                <dl class="grid gap-4 py-2 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Service Group Code</dt>
                        <dd class="mt-1 font-mono font-bold text-slate-900 dark:text-white">{{ $consultant->scn_code ?? $consultant->consultant_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Suspended since</dt>
                        <dd class="mt-1 font-semibold text-slate-900 dark:text-white">{{ optional($consultant->finance_suspended_at)->format('M d, Y') ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($heldTotal > 0)
                    <div class="py-4">
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            <strong class="text-slate-900 dark:text-white">LKR {{ number_format($heldTotal) }}</strong> of your service income is on hold.
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            This money is safe — it is not lost, and it will be released automatically once your account is reactivated.
                        </p>
                    </div>
                @endif

                <div class="py-4">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">How to get reactivated</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        Contact an administrator to have your account reactivated.
                        @if ($contactEmail)
                            <a href="mailto:{{ $contactEmail }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $contactEmail }}</a>
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 pt-4">
                    <a href="{{ route('reports.finance') }}" class="btn-secondary">View your finance week history</a>
                    <a href="{{ route('profile.edit') }}" class="btn-secondary">My Profile</a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
