<x-layouts.public :title="null">
    {{-- ===================== HERO =====================
         Full-bleed photo with the headline over it, as requested.

         ⚠️ THE PHOTO IS NOT IN THE REPO YET. Drop the celebrating-students
         image at public/images/hero-students.jpg and it appears here; until
         then <x-site-image> paints a themed gradient at the same size, so the
         page is never broken-looking. Same for the section below.

         The dark scrim is NOT decoration — white text on an unknown photo is
         unreadable without it, and it must stay whatever image is used. --}}
    <section class="relative isolate overflow-hidden">
        <div class="absolute inset-0 -z-10">
            <x-site-image
                path="images/hero-students.jpg"
                alt="ETEC students celebrating together on campus"
                class="animate-kenburns"
                :eager="true"
            />
            <div class="absolute inset-0 bg-slate-950/65 dark:bg-slate-950/75"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-slate-950/40"></div>
        </div>

        <div class="container-app flex min-h-[78vh] flex-col items-center justify-center py-24 text-center">
            <span class="reveal badge bg-white/10 text-white ring-1 ring-white/20 backdrop-blur">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                Learning that pays you back
            </span>

            <h1 class="reveal reveal-d1 mt-6 max-w-4xl text-4xl font-extrabold leading-[1.08] tracking-tight text-white sm:text-8xl lg:text-7xl">
                Where Sri Lanka Builds
                <span class="bg-gradient-to-r from-brand-300 to-accent-500 bg-clip-text text-transparent">Its Digital Future</span>
            </h1>

            <p class="reveal reveal-d2 mt-6 max-w-2xl text-lg leading-relaxed text-white/80">
                Practical, career-first training in business, technology and design — taught by people who
                do the work, and backed by certificates you can actually show an employer.
            </p>

            <div class="reveal reveal-d3 mt-10 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('courses.index') }}" class="btn-primary px-8 py-3.5 text-base shadow-lg shadow-brand-900/30">
                    Start your journey
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a href="{{ route('about') }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/5 px-8 py-3.5 text-base font-semibold text-white backdrop-blur transition hover:bg-white/15">
                    Who we are
                </a>
            </div>
        </div>
    </section>

    {{-- ===================== WELCOME ===================== --}}
    <section class="container-app grid items-center gap-12 py-20 lg:grid-cols-2 lg:py-28">
        <div class="reveal">
            <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                Welcome to
                <span class="bg-gradient-to-r from-brand-600 to-brand-400 bg-clip-text text-transparent">ETEC</span>
            </h2>

            <div class="mt-6 space-y-4 text-slate-600 dark:text-slate-300">
                <p>
                    We train people for the work that exists now — freelancing, digital business, marketing and
                    design — with courses short enough to finish and practical enough to use the week after.
                </p>
                <p>
                    Nothing here stops at theory. Every course is built around work you actually produce, so you
                    leave with a portfolio and a certificate rather than a folder of notes.
                </p>
                <p>
                    Whether you are starting from nothing or sharpening skills you already have, you will not be
                    doing it alone. Learn with us, earn with us, and grow at your own pace.
                </p>
            </div>

            <div class="mt-8 flex flex-wrap gap-x-10 gap-y-4">
                @foreach ([
                    ['v' => number_format($stats['students']).'+', 'l' => 'Students'],
                    ['v' => number_format($stats['courses']), 'l' => 'Courses'],
                    
                    ['v' => number_format($stats['certificates']).'+', 'l' => 'Certificates Issued'],
                   
                ] as $i => $m)
                    <div class="reveal reveal-d{{ $i + 1 }}">
                        <div class="text-3xl font-extrabold text-slate-900 dark:text-white">{{ $m['v'] }}</div>
                        <div class="text-sm text-slate-500 dark:text-slate-400">{{ $m['l'] }}</div>
                    </div>
                @endforeach
            </div>

            <a href="{{ route('about') }}" class="btn-primary mt-8 px-7 py-3 text-base">Explore our story</a>
        </div>

        {{-- The framed photo, mirroring the reference layout. --}}
        <div class="reveal reveal-d2 relative">
            <div class="overflow-hidden rounded-2xl border-4 border-brand-700 shadow-xl transition-transform duration-500 hover:-translate-y-1 dark:border-brand-500">
                <div class="aspect-[4/3]">
                    <x-site-image
                        path="images/about-students.jpg"
                        alt="ETEC students working together on laptops"
                    />
                </div>
            </div>
            <div class="pointer-events-none absolute -bottom-6 -left-6 -z-10 hidden h-40 w-40 rounded-2xl bg-brand-600/15 lg:block dark:bg-brand-500/20"></div>
        </div>
    </section>

    {{-- ===================== STATS ===================== --}}
    <section class="border-y border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="container-app grid grid-cols-2 gap-6 py-10 lg:grid-cols-4">
            <!-- @php
                $statCards = [
                    ['label' => 'Active Students', 'value' => $stats['students'], 'suffix' => '+', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 20a8 8 0 0116 0'],
                    ['label' => 'Courses', 'value' => $stats['courses'], 'suffix' => '', 'icon' => 'M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3'],
                    ['label' => 'Consultants', 'value' => $stats['consultants'], 'suffix' => '+', 'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z'],
                    ['label' => 'Certificates Issued', 'value' => $stats['certificates'], 'suffix' => '+', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                ];
            @endphp
            @foreach ($statCards as $s) -->
                <!-- <div class="flex items-center gap-4">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}"/></svg>
                    </span>
                    <div>
                        <div class="text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl">{{ number_format($s['value']) }}{{ $s['suffix'] }}</div>
                        <div class="text-sm text-slate-500 dark:text-slate-400">{{ $s['label'] }}</div>
                    </div>
                </div>
            @endforeach -->
        </div>
    </section>

    {{-- ===================== FEATURES ===================== --}}
    <section class="container-app py-20">
        <!-- <x-section-heading center eyebrow="Why ETEC" title="Everything you need to succeed" subtitle="A complete learning experience built for modern students and ambitious consultants." /> -->
        <div class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @php
                $features = [
                    ['img' => 'expert-led-courses', 't' => 'Expert-led Courses', 'd' => 'Learn from industry professionals with practical, career-focused curriculum.', 'i' => 'M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3', 'c' => 'brand'],
                    ['img' => 'recognized-certificates', 't' => 'Recognized Certificates', 'd' => 'Earn verifiable certificates you can share with employers instantly.', 'i' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'c' => 'emerald'],
                    ['img' => 'live-zoom-classes', 't' => 'Live Zoom Classes', 'd' => 'Join interactive live sessions and access recordings anytime.', 'i' => 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z', 'c' => 'rose'],
                    // ['t' => 'Earn as a Consultant', 'd' => 'Refer students and earn up to LKR 15,000 per group — fully transparent.', 'i' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2', 'c' => 'accent'],
                    ['img' => 'learn-on-any-device', 't' => 'Learn on Any Device', 'd' => 'Fully responsive — study on your phone, tablet, or computer seamlessly.', 'i' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z', 'c' => 'violet'],
                    ['img' => 'track-your-progress', 't' => 'Track Your Progress', 'd' => 'Visual dashboards keep you motivated with real-time progress tracking.', 'i' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'c' => 'sky'],
                ];
                $colorMap = [
                    'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
                    'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
                    'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
                    'accent' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
                    'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
                    'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
                ];
            @endphp
            @foreach ($features as $f)
                {{-- A picture for each box (2026-11-02) — free Unsplash photos, no watermark,
                     saved in public/images/home so the page never depends on another site. --}}
                <div class="card group overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                    @if (! empty($f['img']))
                        <div class="overflow-hidden" style="aspect-ratio: 16 / 9">
                            {{-- x-site-image: a missing file shows a themed block, never broken alt text. --}}
                            <x-site-image :path="'images/home/'.$f['img'].'.jpg'" :alt="$f['t']" class="transition duration-500 group-hover:scale-105" />
                        </div>
                    @endif
                    <div class="p-6">
                    <span class="grid h-12 w-12 place-items-center rounded-xl {{ $colorMap[$f['c']] }} transition group-hover:scale-110">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $f['i'] }}"/></svg>
                    </span>
                    <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">{{ $f['t'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ $f['d'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ===================== FEATURED COURSES ===================== --}}
    <section class="bg-white py-20 dark:bg-slate-900">
        <div class="container-app">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <x-section-heading eyebrow="Popular Courses" title="Start learning today" subtitle="Hand-picked courses to launch or level up your career." />
                <a href="{{ route('courses.index') }}" class="btn-secondary">View all courses</a>
            </div>

            @if ($featuredCourses->isEmpty())
                <div class="mt-12 card p-12 text-center">
                    <p class="text-slate-500 dark:text-slate-400">Courses are coming soon. Check back shortly!</p>
                </div>
            @else
                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featuredCourses as $course)
                        <x-course-card :course="$course" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>



    

    

    {{-- ===================== LATEST BLOG ===================== --}}
    @if ($latestPosts->isNotEmpty())
        <section class="bg-white py-20 dark:bg-slate-900">
            <div class="container-app">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <x-section-heading eyebrow="Blog" title="Latest from our blog" subtitle="Tips, insights, and stories from the ETEC community." />
                    <a href="{{ route('blog.index') }}" class="btn-secondary">Read all posts</a>
                </div>
                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    @foreach ($latestPosts as $post)
                        <x-blog-card :post="$post" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ===================== CTA ===================== --}}
    <!-- <section class="container-app py-20">
        <div class="relative overflow-hidden rounded-3xl bg-slate-900 px-8 py-16 text-center dark:bg-slate-800 sm:px-16">
            <div class="pointer-events-none absolute inset-0 -z-0 opacity-40">
                <div class="absolute -left-20 top-0 h-72 w-72 rounded-full bg-brand-500/30 blur-3xl"></div>
                <div class="absolute -right-20 bottom-0 h-72 w-72 rounded-full bg-accent-500/20 blur-3xl"></div>
            </div>
            <div class="relative">
                <h2 class="mx-auto max-w-2xl text-3xl font-extrabold text-white sm:text-4xl">Ready to start your journey with ETEC?</h2>
                <p class="mx-auto mt-4 max-w-xl text-white/70">Join thousands of learners and consultants building their future today. It's free to get started.</p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="btn-primary px-8 py-3 text-base">Create free account</a>
                    <a href="{{ route('courses.index') }}" class="btn-secondary border-white/20 bg-white/10 px-8 py-3 text-base text-white hover:bg-white/20">Browse courses</a>
                </div>
            </div>
        </div>
    </section> -->
</x-layouts.public>
