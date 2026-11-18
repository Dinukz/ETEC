<x-layouts.public title="Verify Certificate">
    <section class="bg-slate-50 py-16 dark:bg-slate-950 lg:py-24">
        <div class="mx-auto max-w-2xl px-4 sm:px-6">
            <div class="text-center">
                <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white">Verify a Certificate</h1>
                <p class="mt-2 text-slate-500 dark:text-slate-400">Enter the certificate number or verification code printed on the certificate.</p>
            </div>

            <form method="GET" action="{{ route('certificates.verify') }}" class="card mt-8 flex gap-3 p-4">
                <input type="text" name="code" value="{{ $code }}" placeholder="e.g. CERT-AB12CD34" required class="input flex-1">
                <button type="submit" class="btn-primary">Verify</button>
            </form>

            @if ($searched)
                @if ($certificate)
                    <div class="card mt-6 p-8 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <h2 class="mt-4 text-xl font-bold text-slate-900 dark:text-white">Valid Certificate</h2>
                        <dl class="mt-5 space-y-2 text-left text-sm">
                            <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800"><dt class="text-slate-400">Holder</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ $certificate->user->name }}</dd></div>
                            <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800"><dt class="text-slate-400">Course</dt><dd class="font-semibold text-slate-900 dark:text-white">{{ $certificate->course->title }}</dd></div>
                            <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800"><dt class="text-slate-400">Certificate No.</dt><dd class="font-mono text-slate-700 dark:text-slate-200">{{ $certificate->certificate_number }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-400">Issued</dt><dd class="text-slate-700 dark:text-slate-200">{{ optional($certificate->issued_at)->format('F d, Y') }}</dd></div>
                        </dl>
                    </div>
                @else
                    <div class="card mt-6 p-8 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-500/15">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                        <h2 class="mt-4 text-xl font-bold text-slate-900 dark:text-white">No matching certificate</h2>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">We couldn't find an issued certificate for "{{ $code }}". Check the code and try again.</p>
                    </div>
                @endif
            @endif
        </div>
    </section>
</x-layouts.public>
