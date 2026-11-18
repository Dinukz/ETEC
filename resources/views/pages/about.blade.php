<x-layouts.public title="About Us">
    <x-page-header
        title="About ETEC"
        subtitle="Empowering learners and rewarding those who help others grow."
        :breadcrumbs="['About' => null]"
    />

    {{-- Mission --}}
    <section class="container-app py-16">
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div>
                <span class="badge bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">Our Mission</span>
                @if (!empty($page))
                    <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $page->title }}</h2>
                    <div class="prose prose-slate mt-5 max-w-none dark:prose-invert text-slate-600 dark:text-slate-300">{!! $page->content !!}</div>
                @else
                    <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">Making quality education accessible & rewarding</h2>
                    <p class="mt-5 text-slate-600 dark:text-slate-300">ETEC was founded on a simple belief: education should open doors — not just for students, but for everyone in the community who helps others learn.</p>
                    <p class="mt-4 text-slate-600 dark:text-slate-300">We combine expert-led professional courses with a transparent referral program, creating a platform where students gain valuable skills and consultants earn real income by growing our community.</p>
                @endif
                <div class="mt-8 grid grid-cols-2 gap-4">
                    @foreach ([['t' => 'Practical', 'd' => 'Real-world, career-focused curriculum'], ['t' => 'Transparent', 'd' => 'Clear, database-driven commissions'], ['t' => 'Accessible', 'd' => 'Learn on any device, anytime'], ['t' => 'Rewarding', 'd' => 'Earn while you grow the community']] as $v)
                        <div class="card p-4">
                            <h4 class="font-bold text-slate-900 dark:text-white">{{ $v['t'] }}</h4>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $v['d'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
            {{-- ⚠️ OUR OWN CAMPUS (2026-11-02) — the building on Galle Road and the
                 reception, the owner's photos, in public/images/about. Replaces a
                 plain gradient square; its "Learn. Earn. Grow." line sits on top. --}}
            <div class="relative" style="padding-bottom: 2.5rem">
                <div class="grid grid-cols-2 gap-4">
                    <figure class="overflow-hidden rounded-3xl shadow-lg" style="aspect-ratio: 3 / 4">
                        <x-site-image path="images/about/etec-building-wadduwa.jpg" alt="The E-TEC building on Galle Road, Molligoda, Wadduwa" />
                    </figure>
                    <figure class="mt-12 overflow-hidden rounded-3xl shadow-lg" style="aspect-ratio: 3 / 4">
                        <x-site-image path="images/about/etec-office-reception.jpg" alt="The E-TEC reception and waiting area" />
                    </figure>
                </div>
                <div class="absolute bottom-0 left-4 max-w-xs rounded-2xl bg-gradient-to-br from-brand-500 via-brand-700 to-brand-900 p-5 text-white shadow-xl">
                    <p class="text-2xl font-extrabold">Learn. Earn. Grow.</p>
                    <p class="mt-1 text-sm text-white/70">Visit us at our campus in Molligoda, Wadduwa.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Values --}}
    <section class="bg-white py-16 dark:bg-slate-900">
        <div class="container-app">
            <x-section-heading center eyebrow="What drives us" title="Our core values" />
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['t' => 'Student Success', 'd' => 'Every decision we make starts with what is best for our learners.', 'i' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['t' => 'Integrity', 'd' => 'Transparent commissions, honest pricing, and fair treatment for all.', 'i' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['t' => 'Community', 'd' => 'We grow stronger together — students, lecturers, and consultants alike.', 'i' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z'],
                ] as $v)
                    <div class="card p-7 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $v['i'] }}"/></svg></span>
                        <h3 class="mt-5 text-lg font-bold text-slate-900 dark:text-white">{{ $v['t'] }}</h3>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $v['d'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Testimonials --}}
    @if ($testimonials->isNotEmpty())
        <section class="container-app py-16">
            <x-section-heading center eyebrow="Testimonials" title="What our community says" />
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ($testimonials as $t)
                    <figure class="card p-6">
                        <div class="flex items-center gap-1 text-accent-500">@for ($i = 0; $i < ($t->rating ?? 5); $i++)<svg class="h-4 w-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>@endfor</div>
                        <blockquote class="mt-4 text-sm text-slate-600 dark:text-slate-300">"{{ $t->content }}"</blockquote>
                        <figcaption class="mt-5 flex items-center gap-3 border-t border-slate-100 pt-5 dark:border-slate-800">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 font-bold text-white">{{ strtoupper(substr($t->name, 0, 1)) }}</span>
                            <div><div class="text-sm font-bold text-slate-900 dark:text-white">{{ $t->name }}</div>@if ($t->designation)<div class="text-xs text-slate-500 dark:text-slate-400">{{ $t->designation }}</div>@endif</div>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif

    {{-- CTA --}}
    <section class="container-app pb-16">
        <div class="rounded-3xl bg-gradient-to-br from-brand-700 to-brand-950 p-10 text-center text-white lg:p-14">
            <h2 class="text-2xl font-extrabold sm:text-3xl">Join the ETEC community today</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/70">Whether you want to learn a new skill or earn as a consultant, there is a place for you here.</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                
                <a href="{{ route('courses.index') }}" class="btn-secondary border-white/20 bg-white/10 px-7 py-3 text-base text-white hover:bg-white/20">Browse courses</a>
            </div>
        </div>
    </section>
</x-layouts.public>
