{{--
    ⚠️ The sample roles were REMOVED on 2026-11-02 — $openings is empty, so the
    page shows "Nothing open right now". Add only vacancies that really exist.
    (Originally sample content:)

    Added 2026-09-02 at the owner's request ("Careers eke thiyen detail sample
    widiyta dann"). Everything a person reads comes from the arrays below.

    ⚠️ BEFORE THIS GOES LIVE, either replace $openings with vacancies that
    genuinely exist or set it to an empty array — the page then shows the
    "nothing open right now" state instead. Advertising jobs that do not exist
    wastes real people's time and is the one thing on this page that actually
    matters. Applications go to the contact page; there is no separate inbox.
--}}
<x-layouts.public title="Careers">
    @php
        // ⚠️ Sample roles removed 2026-11-02 (owner: "Open positions sample tika ain
        // krla denn"). The page shows its "Nothing open right now" card until real
        // vacancies are added here, each as ['title', 'type', 'place', 'body'].
        $openings = [];

        $benefits = [
            ['t' => 'Learn on the job', 'd' => 'Sit in on any course we run, free, for as long as you work here.', 'i' => 'M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3'],
            ['t' => 'Flexible hours', 'd' => 'Teaching runs evenings and weekends, so the working week bends around it.', 'i' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['t' => 'Work that shows', 'd' => 'Small team, visible results — you will know exactly what your work changed.', 'i' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10'],
            ['t' => 'Room to grow', 'd' => 'We promote from inside first, and say so plainly when we cannot.', 'i' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
        ];
    @endphp

    <section class="border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50">
        <div class="container-app py-16 text-center lg:py-20">
            <h1 class="reveal text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-5xl">
                Build Your Career <span class="bg-gradient-to-r from-brand-600 to-brand-400 bg-clip-text text-transparent">With Us</span>
            </h1>
            <p class="reveal reveal-d1 mx-auto mt-5 max-w-2xl text-lg text-slate-600 dark:text-slate-300">
                We are a small team teaching skills that change what someone can earn. If that is work you want
                to do, we would like to hear from you.
            </p>
        </div>
    </section>

    <section class="container-app py-16 lg:py-20">
        <h2 class="reveal text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl">Why work here</h2>
        <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($benefits as $i => $b)
                <div class="reveal reveal-d{{ ($i % 3) + 1 }} card p-6">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $b['i'] }}"/></svg>
                    </span>
                    <h3 class="mt-4 font-bold text-slate-900 dark:text-white">{{ $b['t'] }}</h3>
                    <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-300">{{ $b['d'] }}</p>
                </div>
            @endforeach
        </div>

        <h2 class="reveal mt-16 text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl">Open positions</h2>

        @if (empty($openings))
            <div class="reveal card mt-8 p-10 text-center">
                <p class="text-slate-600 dark:text-slate-300">
                    Nothing open right now. Send us your CV anyway — we keep them, and we do look.
                </p>
                <a href="{{ route('contact') }}" class="btn-primary mt-6 px-7 py-3">Get in touch</a>
            </div>
        @else
            <div class="mt-8 space-y-4">
                @foreach ($openings as $i => $job)
                    <div class="reveal reveal-d{{ ($i % 3) + 1 }} card flex flex-col gap-4 p-6 transition duration-300 hover:shadow-lg sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ $job['title'] }}</h3>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $job['type'] }}</span>
                                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $job['place'] }}</span>
                            </div>
                            <p class="mt-3 max-w-2xl text-sm text-slate-600 dark:text-slate-300">{{ $job['body'] }}</p>
                        </div>
                        <a href="{{ route('contact') }}" class="btn-primary shrink-0 px-6 py-2.5">Apply</a>
                    </div>
                @endforeach
            </div>

            <p class="reveal mt-8 text-sm text-slate-500 dark:text-slate-400">
                Do not see your role? Write to us anyway and tell us what you would do here.
            </p>
        @endif
    </section>
</x-layouts.public>
