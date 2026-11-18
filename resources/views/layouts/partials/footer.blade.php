<footer class="border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
    <div class="container-app py-14">
        <div class="grid gap-10 lg:grid-cols-12">
            {{-- Brand + intro --}}
            <div class="lg:col-span-3">
                <x-logo />
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                    ETEC is a modern learning platform helping students gain professional skills and
                    certifications — while rewarding consultants who grow our community.
                </p>
                <div class="mt-6 flex gap-3">
                    @foreach (['facebook' => 'M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z', 'twitter' => 'M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z', 'linkedin' => 'M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-4 0v7h-4v-7a6 6 0 016-6zM6 9H2v12h4zM4 6a2 2 0 100-4 2 2 0 000 4z', 'instagram' => 'M12 2.2c3.2 0 3.6 0 4.8.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.3 1 .4 2.2.1 1.2.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 1.2-.2 1.8-.4 2.2a3.8 3.8 0 01-.9 1.4c-.4.4-.8.7-1.4.9-.4.2-1 .3-2.2.4-1.2.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-1.2-.1-1.8-.2-2.2-.4a3.8 3.8 0 01-1.4-.9 3.8 3.8 0 01-.9-1.4c-.2-.4-.3-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.8c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.3 2.2-.4C8.4 2.2 8.8 2.2 12 2.2zm0 3.4a6.4 6.4 0 100 12.8 6.4 6.4 0 000-12.8zm0 10.6a4.2 4.2 0 110-8.4 4.2 4.2 0 010 8.4zm6.6-10.9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z'] as $icon => $path)
                        <a href="#" aria-label="{{ $icon }}" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-600 dark:border-slate-800 dark:text-slate-400 dark:hover:border-brand-500/40 dark:hover:bg-brand-500/10">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" /></svg>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Link columns --}}
            @php
                $cols = [
                    // 'Platform' => [['Courses', route('courses.index')], ['Become a Consultant', route('register')], ['Blog', route('blog.index')], ['Pricing', route('courses.index')]],
                    'Company'  => [['About Us', route('about')], ['Contact', route('contact')], ['FAQ', route('faq')], ['Careers', '#']],
                    'Legal'    => [['Privacy Policy', '#'], ['Terms of Service', '#'], ['Refund Policy', '#'], ['Certificate Verify', '#']],
                ];
            @endphp
            @foreach ($cols as $heading => $links)
                <div class="lg:col-span-2">
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $heading }}</h4>
                    <ul class="mt-4 space-y-3">
                        @foreach ($links as [$label, $url])
                            <li><a href="{{ $url }}" class="text-sm text-slate-500 transition hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            {{-- Newsletter --}}
            <div class="lg:col-span-2">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Stay updated</h4>
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Get course updates & offers.</p>
                <form action="{{ route('contact') }}" method="GET" class="mt-3 flex flex-col gap-2">
                    <input type="email" name="email" placeholder="you@email.com" class="input py-2 text-xs" aria-label="Email">
                    <button type="submit" class="btn-primary w-full py-2 text-xs">Subscribe</button>
                </form>
            </div>

            {{-- Where we are (2026-11-02) — zoomable map; see partials.location-map. --}}
            <div class="lg:col-span-3">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Find us</h4>
                <div class="mt-4">
                    @include('partials.location-map', ['height' => 180])
                </div>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-slate-200 pt-8 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400 sm:flex-row">
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'ETEC') }}. All rights reserved.</p>
            <p>Made with <span class="text-rose-500">&hearts;</span> in Sri Lanka</p>
        </div>
    </div>
</footer>
