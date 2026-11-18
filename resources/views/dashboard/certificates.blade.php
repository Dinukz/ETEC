<x-layouts.app title="Certificates" header="Certificates">
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">My Certificates</h2>
        <p class="mt-1 text-slate-500 dark:text-slate-400">Your earned certificates — download and share them anytime.</p>
    </div>

    @if ($certificates->isEmpty())
        <div class="card p-16 text-center">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </span>
            <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">No certificates yet</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Complete a course to earn your first certificate.</p>
            <a href="{{ route('student.courses') }}" class="btn-primary mt-6">Go to my courses</a>
        </div>
    @else
        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($certificates as $cert)
                <div class="card overflow-hidden">
                    {{-- Certificate preview banner --}}
                    <div class="relative bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 p-6 text-white">
                        <div class="flex items-start justify-between">
                            <svg class="h-10 w-10 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="badge bg-emerald-500/90 text-white">Verified</span>
                        </div>
                        <p class="mt-4 text-xs uppercase tracking-widest text-white/60">Certificate of Completion</p>
                        <h3 class="mt-1 text-lg font-bold">{{ $cert->course->title ?? 'Course' }}</h3>
                    </div>
                    <div class="p-5">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">Certificate No.</dt>
                                <dd class="font-mono font-medium text-slate-700 dark:text-slate-200">{{ $cert->certificate_number }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500 dark:text-slate-400">Issued</dt>
                                <dd class="font-medium text-slate-700 dark:text-slate-200">{{ optional($cert->issued_at)->format('M d, Y') }}</dd>
                            </div>
                        </dl>
                        <div class="mt-5 flex gap-3">
                            <a href="{{ route('student.certificates.view', $cert) }}" target="_blank" class="btn-primary flex-1 py-2.5 text-sm">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                View / Print
                            </a>
                            <button class="btn-secondary py-2.5 text-sm" onclick="navigator.clipboard?.writeText('{{ $cert->verification_code }}')">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
