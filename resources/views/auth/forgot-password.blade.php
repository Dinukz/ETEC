<x-layouts.auth title="Forgot password">
    <div>
        <a href="{{ route('login') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to sign in
        </a>
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Forgot your password?</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No worries — enter your email and we'll send you a reset link.</p>
    </div>

    <x-flash />

    @error('email')
        <div class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf
        <div>
            <label for="email" class="label">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input" placeholder="you@email.com">
        </div>
        <button type="submit" class="btn-primary w-full py-3">Send reset link</button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-500 dark:text-slate-400">
        Remembered it?
        <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Sign in</a>
    </p>
</x-layouts.auth>
