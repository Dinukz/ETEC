<x-layouts.public title="Consultant Program">
    <x-page-header
        title="Become an ETEC Consultant"
        subtitle="Refer students, build groups, and earn transparent commissions — paid on real, verified enrollments."
        :breadcrumbs="['Consultant Program' => null]"
    />

    {{-- Earning highlight --}}
    <section class="container-app py-14">
        <div class="grid items-center gap-10 lg:grid-cols-2">
            <div>
                <span class="badge bg-accent-100 text-accent-700 dark:bg-accent-500/10 dark:text-accent-400">Earn up to LKR {{ number_format($settings->max_commission) }}</span>
                <h2 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">Turn your network into steady income</h2>
                <p class="mt-5 text-slate-600 dark:text-slate-300">
                    Fill a group of {{ $settings->group_size }} verified students and earn up to
                    <span class="font-bold text-brand-600 dark:text-brand-400">LKR {{ number_format($settings->max_commission) }}</span> per group.
                    Every commission is calculated from database-driven rules — fully transparent, no surprises.
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="btn-primary px-7 py-3 text-base">Join as Consultant</a>
                    <a href="{{ route('contact') }}" class="btn-secondary px-7 py-3 text-base">Ask a question</a>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="card p-6 text-center">
                    <div class="text-3xl font-extrabold text-brand-600 dark:text-brand-400">{{ $settings->group_size }}</div>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">Students per group</div>
                </div>
                <div class="card p-6 text-center">
                    <div class="text-3xl font-extrabold text-accent-600 dark:text-accent-400">LKR {{ number_format($settings->max_commission) }}</div>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">Max per group</div>
                </div>
                <div class="card p-6 text-center">
                    <div class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">LKR {{ number_format($settings->commission_per_student) }}</div>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">Per student</div>
                </div>
                <div class="card p-6 text-center">
                    <div class="text-3xl font-extrabold text-violet-600 dark:text-violet-400">100%</div>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">Transparent</div>
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="bg-white py-16 dark:bg-slate-900">
        <div class="container-app">
            <x-section-heading center eyebrow="How it works" title="Four simple steps to earning" />
            <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['n' => '1', 't' => 'Sign up free', 'd' => 'Create your consultant account and get a unique referral code.'],
                    ['n' => '2', 't' => 'Share your code', 'd' => 'Invite students to enroll using your referral code.'],
                    ['n' => '3', 't' => 'Build your group', 'd' => 'As students pay and get verified, they auto-fill your group.'],
                    ['n' => '4', 't' => 'Get paid', 'd' => 'Earn commission per student, capped per completed group.'],
                ] as $step)
                    <div class="card p-6">
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-brand-600 text-lg font-bold text-white">{{ $step['n'] }}</span>
                        <h3 class="mt-4 font-bold text-slate-900 dark:text-white">{{ $step['t'] }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $step['d'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="container-app py-16">
        <div class="rounded-3xl bg-gradient-to-br from-brand-700 to-brand-950 p-10 text-center text-white lg:p-14">
            <h2 class="text-2xl font-extrabold sm:text-3xl">Ready to start earning?</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/70">Join our growing network of consultants across Sri Lanka. It's free to start.</p>
            <a href="{{ route('register') }}" class="btn-accent mt-8 px-8 py-3 text-base">Create consultant account</a>
        </div>
    </section>
</x-layouts.public>
