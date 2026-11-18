<x-layouts.public :title="$course->title">
    @php
        $price = (float) ($course->discount_price ?? $course->price);
        $hasDiscount = $course->discount_price && $course->discount_price < $course->price;
        $currency = $course->currency ?? 'LKR';
        $hours = intdiv($totalDuration, 60);
        $mins = $totalDuration % 60;
    @endphp

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-slate-900 text-white">
        <div class="pointer-events-none absolute inset-0 opacity-40">
            <div class="absolute -left-20 top-0 h-72 w-72 rounded-full bg-brand-500/40 blur-3xl"></div>
            <div class="absolute right-0 bottom-0 h-72 w-72 rounded-full bg-accent-500/20 blur-3xl"></div>
        </div>
        <div class="container-app relative grid gap-10 py-12 lg:grid-cols-3 lg:py-16">
            <div class="lg:col-span-2">
                <nav class="mb-5 flex flex-wrap items-center gap-2 text-sm text-white/60">
                    <a href="{{ url('/') }}" class="hover:text-white">Home</a>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    <a href="{{ route('courses.index') }}" class="hover:text-white">Courses</a>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    <span class="text-white/90">{{ \Illuminate\Support\Str::limit($course->title, 40) }}</span>
                </nav>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($course->category)<span class="badge bg-white/15 text-white">{{ $course->category }}</span>@endif
                    @if ($course->level)<span class="badge bg-white/15 text-white">{{ ucfirst($course->level) }}</span>@endif
                    @if ($course->is_featured)<span class="badge bg-accent-500 text-slate-900">Featured</span>@endif
                </div>

                <h1 class="mt-4 text-3xl font-extrabold leading-tight sm:text-4xl lg:text-5xl">{{ $course->title }}</h1>
                @if ($course->subtitle)<p class="mt-4 max-w-2xl text-lg text-white/80">{{ $course->subtitle }}</p>@endif

                <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-white/80">
                    @if ($course->lecturer)
                        <span class="inline-flex items-center gap-2"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 20a8 8 0 0116 0"/></svg> {{ $course->lecturer->name }}</span>
                    @endif
                    <span class="inline-flex items-center gap-2"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3"/></svg> {{ $totalLessons }} lessons</span>
                    @if ($totalDuration > 0)
                        <span class="inline-flex items-center gap-2"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg> {{ $hours ? $hours.'h ' : '' }}{{ $mins }}m</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="container-app py-12">
        <div class="grid gap-10 lg:grid-cols-3">
            {{-- Main content --}}
            <div class="space-y-10 lg:col-span-2">
                {{-- Overview --}}
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">About this course</h2>
                    <div class="prose prose-slate mt-4 max-w-none text-slate-600 dark:prose-invert dark:text-slate-300">
                        {!! nl2br(e($course->description)) !!}
                    </div>
                </div>

                {{-- Curriculum --}}
                @if ($course->modules->isNotEmpty() || $course->lessons->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between">
                            <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Curriculum</h2>
                            <span class="text-sm text-slate-500 dark:text-slate-400">{{ $totalLessons }} lessons</span>
                        </div>

                        <div class="mt-5 space-y-3" x-data="{ open: 0 }">
                            @forelse ($course->modules as $mi => $module)
                                <div class="card overflow-hidden">
                                    <button @click="open = open === {{ $mi }} ? null : {{ $mi }}" class="flex w-full items-center justify-between gap-4 p-5 text-left">
                                        <span class="flex items-center gap-3">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-sm font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $mi + 1 }}</span>
                                            <span>
                                                <span class="block font-semibold text-slate-900 dark:text-white">{{ $module->title }}</span>
                                                <span class="text-xs text-slate-400">{{ $module->lessons->count() }} lessons</span>
                                            </span>
                                        </span>
                                        <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform" :class="open === {{ $mi }} && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div x-show="open === {{ $mi }}" x-collapse>
                                        <ul class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
                                            @foreach ($module->lessons as $lesson)
                                                <li class="flex items-center justify-between gap-3 px-5 py-3.5">
                                                    <span class="flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300">
                                                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><circle cx="12" cy="12" r="9"/></svg>
                                                        {{ $lesson->title }}
                                                    </span>
                                                    <span class="flex items-center gap-3 text-xs text-slate-400">
                                                        @if ($lesson->is_preview)<span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">Preview</span>@endif
                                                        @if ($lesson->duration_minutes)<span>{{ $lesson->duration_minutes }}m</span>@endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            @empty
                                {{-- No modules: flat lesson list --}}
                                <div class="card divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($course->lessons as $lesson)
                                        <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                                            <span class="flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300">
                                                <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                                                {{ $lesson->title }}
                                            </span>
                                            @if ($lesson->duration_minutes)<span class="text-xs text-slate-400">{{ $lesson->duration_minutes }}m</span>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endif

                {{-- Instructor --}}
                @if ($course->lecturer)
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Your instructor</h2>
                        <div class="card mt-4 flex items-start gap-5 p-6">
                            <span class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-brand-600 text-2xl font-bold text-white">{{ strtoupper(substr($course->lecturer->name, 0, 1)) }}</span>
                            <div>
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ $course->lecturer->name }}</h3>
                                <p class="text-sm text-brand-600 dark:text-brand-400">Course Instructor</p>
                                <p class="mt-3 text-sm leading-relaxed text-slate-500 dark:text-slate-400">Experienced educator dedicated to helping students master real-world skills through practical, engaging lessons.</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Sticky sidebar --}}
            <aside class="lg:col-span-1">
                <div class="lg:sticky lg:top-24">
                    <div class="card overflow-hidden">
                        <div class="aspect-video bg-slate-100 dark:bg-slate-800">
                            @if ($course->thumbnail)
                                <img src="{{ $course->thumbnail_url }}" alt="{{ $course->title }}" class="h-full w-full object-cover">
                            @else
                                <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 text-white"><svg class="h-14 w-14 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><circle cx="12" cy="12" r="9"/></svg></div>
                            @endif
                        </div>
                        <div class="p-6">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-extrabold text-slate-900 dark:text-white">{{ $currency }} {{ number_format($price) }}</span>
                                @if ($hasDiscount)<span class="text-lg text-slate-400 line-through">{{ number_format($course->price) }}</span>@endif
                            </div>
                            @if ($hasDiscount)
                                <span class="badge mt-2 bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">Save {{ round((1 - $price / $course->price) * 100) }}%</span>
                            @endif

                            @if ($isEnrolled)
                                <a href="{{ route('student.courses') }}" class="btn-primary mt-6 w-full py-3">Go to course</a>
                                <p class="mt-3 text-center text-sm text-emerald-600 dark:text-emerald-400">✓ You are enrolled</p>
                            @else
                                @auth
                                    <a href="{{ route('checkout.show', $course) }}" class="btn-primary mt-6 w-full py-3">Enroll Now</a>
                                @else
                                    <a href="{{ route('register') }}" class="btn-primary mt-6 w-full py-3">Enroll Now</a>
                                    <p class="mt-3 text-center text-sm text-slate-500 dark:text-slate-400">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Sign in</a></p>
                                @endauth
                            @endif

                            <ul class="mt-6 space-y-3 border-t border-slate-100 pt-6 text-sm dark:border-slate-800">
                                @php
                                    $includes = [
                                        ['t' => $totalLessons.' on-demand lessons', 'i' => 'M12 6.25L4 3l8 3.25L20 3l-8 3.25zM4 3v7c2.67 3 11.33 3 14 0V3'],
                                        ['t' => 'Certificate of completion', 'i' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                                        ['t' => 'Access on mobile & desktop', 'i' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z'],
                                        ['t' => 'Lifetime access', 'i' => 'M13 10V3L4 14h7v7l9-11h-7z'],
                                    ];
                                @endphp
                                @foreach ($includes as $inc)
                                    <li class="flex items-center gap-3 text-slate-600 dark:text-slate-300">
                                        <svg class="h-5 w-5 shrink-0 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $inc['i'] }}"/></svg>
                                        {{ $inc['t'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="card mt-4 flex items-center gap-3 p-4">
                        <svg class="h-6 w-6 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-xs text-slate-500 dark:text-slate-400">30-day money-back guarantee. Enroll with confidence.</p>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    {{-- Related --}}
    @if ($related->isNotEmpty())
        <section class="bg-white py-16 dark:bg-slate-900">
            <div class="container-app">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Related courses</h2>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($related as $rel)
                        <x-course-card :course="$rel" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.public>
