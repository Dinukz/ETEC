<x-layouts.auth title="Verify your email">
    <div class="text-center">
        <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </span>
        <h1 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Verify your email</h1>
        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
            We've sent a verification link to <strong class="text-slate-700 dark:text-slate-200">{{ auth()->user()->email }}</strong>.
            Please check your inbox and click the link to activate your account.
        </p>
    </div>

    <x-flash />

    <div class="mt-8 space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn-primary w-full py-3">Resend verification email</button>
        </form>
        <a href="{{ route('dashboard') }}" class="btn-secondary w-full py-3">Continue to dashboard</a>
    </div>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">Sign out</button>
    </form>
</x-layouts.auth>
