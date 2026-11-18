{{--
    ⚠️ SAMPLE CONTENT — WRITTEN TO BE REPLACED.

    Added 2026-09-02 at the owner's request ("Services eke thiyen detail sample
    widiyta dann"), so the nav item leads somewhere finished-looking while the
    real wording is decided.

    Everything a person reads on this page comes from the $services array
    directly below. Edit that; the markup needs no changes. Nothing here is
    pulled from the database on purpose — a marketing page that 500s because a
    table is empty is worse than one with placeholder copy.
--}}
<x-layouts.public title="Services">
    @php
        $services = [
            [
                'title' => 'Career-focused Training',
                'body' => 'Short, practical programmes in digital business, marketing, design and technology — built around what employers and clients are hiring for right now.',
                'points' => ['Live and recorded sessions', 'Project work every module', 'Certificate on completion'],
                'icon' => 'M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3',
                'colour' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
            ],
            [
                'title' => 'Corporate & Team Training',
                'body' => 'Bring a team up to speed together. We shape the syllabus around the tools your people already use and run sessions at times that suit the business.',
                'points' => ['Tailored syllabus', 'On-site or online', 'Progress reporting for managers'],
                'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z',
                'colour' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
            ],
            [
                'title' => 'Mentoring & Career Support',
                'body' => 'Finishing a course is the start. Sit with a mentor to shape a portfolio, prepare for interviews, and work out the first realistic step from where you are.',
                'points' => ['One-to-one sessions', 'Portfolio review', 'Interview preparation'],
                'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'colour' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
            ],
            [
                'title' => 'Freelance Launchpad',
                'body' => 'Turn a skill into income. We cover pricing, finding clients, writing proposals and getting paid — the parts nobody teaches alongside the craft itself.',
                'points' => ['Platform setup', 'Pricing and proposals', 'Getting paid from Sri Lanka'],
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2',
                'colour' => 'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400',
            ],
            [
                'title' => 'Workshops & Events',
                'body' => 'Regular short sessions on one topic — a tool, a technique, a shift worth understanding. Open to students and to anyone who wants to sit in.',
                'points' => ['Weekend and evening slots', 'Beginner-friendly', 'Recordings available'],
                'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                'colour' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
            ],
            [
                'title' => 'Consultant Partnership',
                'body' => 'Introduce people to courses that genuinely help them and earn from every enrolment, with a dashboard that shows exactly what you have earned and when.',
                'points' => ['Transparent earnings', 'Own dashboard', 'Paid on a set schedule'],
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                'colour' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
            ],
        ];
    @endphp

    <section class="border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50">
        <div class="container-app py-16 text-center lg:py-20">
            <h1 class="reveal text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-5xl">
                What We <span class="bg-gradient-to-r from-brand-600 to-brand-400 bg-clip-text text-transparent">Offer</span>
            </h1>
            <p class="reveal reveal-d1 mx-auto mt-5 max-w-2xl text-lg text-slate-600 dark:text-slate-300">
                Training, mentoring and partnership — the whole path from a first lesson to your first paid work.
            </p>
        </div>
    </section>

    <section class="container-app py-16 lg:py-20">
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($services as $i => $s)
                <div class="reveal reveal-d{{ ($i % 3) + 1 }} card p-7 transition duration-300 hover:-translate-y-1 hover:shadow-lg">
                    <span class="grid h-12 w-12 place-items-center rounded-xl {{ $s['colour'] }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}"/></svg>
                    </span>
                    <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">{{ $s['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $s['body'] }}</p>
                    <ul class="mt-5 space-y-2">
                        @foreach ($s['points'] as $point)
                            <li class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="reveal mt-16 rounded-2xl bg-gradient-to-br from-brand-600 to-brand-800 p-10 text-center text-white">
            <h2 class="text-2xl font-extrabold sm:text-3xl">Not sure which one fits you?</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/80">
                Tell us where you are and what you want to be doing in a year. We will point you at the shortest
                honest route — even if that turns out not to be one of ours.
            </p>
            <a href="{{ route('contact') }}" class="mt-7 inline-flex items-center gap-2 rounded-xl bg-white px-7 py-3 font-semibold text-brand-700 transition hover:bg-slate-100">
                Talk to us
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </section>
</x-layouts.public>
