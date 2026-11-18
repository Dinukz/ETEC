<x-layouts.app :title="$lesson->title" :header="$course->title">
    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Player + content --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-hidden">
                @include('student._video-frame-script')

                {{-- ⚠️ EMBEDS: THEIR OWN FULLSCREEN IS SWITCHED OFF (2026-10-01).
                     A YouTube or Vimeo fullscreen takes only the iframe, leaving
                     the E-TEC mark behind. fs=0 / no allowfullscreen hides their
                     button; ours puts the whole frame, mark included, fullscreen. --}}
                @if ($lesson->video_provider === 'youtube' && $lesson->video_url)
                    @php
                        parse_str(parse_url($lesson->video_url, PHP_URL_QUERY) ?? '', $yt);
                        $ytId = $yt['v'] ?? trim(parse_url($lesson->video_url, PHP_URL_PATH) ?? '', '/');
                    @endphp
                    <div class="etec-player relative aspect-video w-full bg-black" x-data="videoFrame()">
                        <iframe class="h-full w-full" src="https://www.youtube.com/embed/{{ $ytId }}?fs=0&rel=0" title="{{ $lesson->title }}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope"></iframe>
                        @include('student._video-watermark')
                        @include('student._video-fullscreen-button')
                    </div>
                @elseif ($lesson->video_provider === 'vimeo' && $lesson->video_url)
                    @php $vimeoId = trim(parse_url($lesson->video_url, PHP_URL_PATH) ?? '', '/'); @endphp
                    <div class="etec-player relative aspect-video w-full bg-black" x-data="videoFrame()">
                        <iframe class="h-full w-full" src="https://player.vimeo.com/video/{{ $vimeoId }}" title="{{ $lesson->title }}" frameborder="0" allow="autoplay"></iframe>
                        @include('student._video-watermark')
                        @include('student._video-fullscreen-button')
                    </div>
                @elseif ($lesson->video_provider === 'upload' && $lesson->video_path)
                    {{-- ⚠️ NO FILE ADDRESS IN THIS PAGE. The player asks for a short-lived
         signed URL the moment somebody presses play, and that request is also
         what opens the viewing window — so opening a lesson by mistake does not
         spend a viewing. See Student\LessonVideoController.

         ⚠️ WHAT THIS DOES AND DOES NOT STOP. It removes the download button,
         the context menu and the picture-in-picture escape, the link it fetches
         dies when the viewing ends, and an E-TEC mark is drawn over the picture.
         It does NOT stop screen recording; nothing on the web can. The owner
         chose this level knowingly on 2026-09-10 ("just block the easy ways")
         and the code should not imply more.

         ⚠️ FULLSCREEN IS OURS, NOT THE BROWSER'S (2026-10-01). The browser's own
         fullscreen shows the bare video and drops the mark, so it is switched
         off (nofullscreen) and the button in the corner puts the whole frame
         fullscreen instead. An iPhone's own fullscreen player cannot draw
         anything from the page — the mark does not show there. --}}
    {{-- ⚠️ STRICTLY TWO VIEWINGS (2026-11-11): every press of the button below is
         one, even after a refresh; when the video ends the button comes back for
         the next one, and at none left the page offers the request instead. See
         App\Services\LessonViewWindow. --}}
    <div class="etec-player relative w-full bg-black" x-data="lessonVideo({
        ticket: '{{ route('student.lesson.video.ticket', [$course, $lesson]) }}',
        csrf: '{{ csrf_token() }}',
        left: {{ (int) $videoViewsLeft }},
    })">
        <video x-ref="player"
            class="aspect-video w-full bg-black"
            controls
            controlsList="nodownload noplaybackrate nofullscreen"
            disablePictureInPicture
            playsinline
            oncontextmenu="return false"
            preload="none"
            x-on:error="recover()"
            x-on:ended="finished()"
            x-show="!locked"></video>

        <div x-show="started && !locked" style="display:none">
            @include('student._video-watermark')
            @include('student._video-fullscreen-button')

            {{-- Light copy first, original on request — only when an SD file exists. --}}
            <div x-show="urls.sd" style="display:none" class="absolute left-3 top-3 z-20 inline-flex gap-1">
                <template x-for="q in ['sd', 'hd']" :key="q">
                    <button type="button" @click="switchQuality(q)"
                        class="rounded-md px-2 py-1 text-xs font-bold"
                        :style="quality === q ? 'background:#fff;color:#0f172a' : 'background:rgba(0,0,0,.55);color:#fff'"
                        x-text="q === 'sd' ? 'SD · faster' : 'HD'"></button>
                </template>
            </div>
        </div>

        <p x-show="notice" x-text="notice" style="display:none" class="px-4 py-2 text-center text-xs text-amber-400"></p>

        {{-- The cover sits over the player until a ticket is granted, so the
             very first click is what asks for one. --}}
        <div x-show="!started && !locked"
            class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-900/90 text-white">
            <button type="button" @click="start()" :disabled="loading"
                class="btn-primary px-6 py-3 text-sm">
                <span x-show="!loading">Learn with video</span>
                <span x-show="loading" x-cloak>Preparing…</span>
            </button>
            <p class="font-semibold text-white/90">
                Viewings left: <span x-text="left">{{ (int) $videoViewsLeft }}</span>
            </p>
            <p class="max-w-sm px-6 text-center text-xs text-white/60">
                You can watch this lesson {{ \App\Services\LessonViewWindow::FREE_VIEWS }} times. Each press of this button is one viewing — even after a refresh or coming back later. Pausing and seeking inside a viewing are fine.
            </p>
        </div>

        {{-- Used up. The only way on is to ask. --}}
        <div x-show="locked" x-cloak
            class="flex aspect-video w-full flex-col items-center justify-center gap-3 bg-slate-900 px-6 text-center text-white">
            <svg class="h-8 w-8 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            <p class="font-semibold">You have used all your viewings of this lesson</p>
            <p class="max-w-sm text-xs text-white/60" x-text="message"></p>
            @if ($videoRequestPending)
                <p class="mt-2 max-w-sm text-sm text-white/80">Your request has been sent. You can watch again once the office approves it.</p>
            @else
                <form method="POST" action="{{ route('student.lesson.video.request', [$course, $lesson]) }}" class="mt-2">
                    @csrf
                    <button type="submit" class="btn-secondary text-sm">Request to watch again</button>
                </form>
            @endif
        </div>
    </div>

    @once
        {{-- ⚠️ INLINE, NOT @push — this layout has no @stack, so a pushed script
             would silently render nowhere. Registered on alpine:init, which is
             safe here because @vite emits a DEFERRED module in <head>: this
             inline script runs during parsing, Alpine.start() runs after. --}}
            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('lessonVideo', (config) => ({
                        started: false,
                        loading: false,
                        // Viewings left; at none the page opens on the request screen.
                        left: config.left,
                        locked: config.left <= 0,
                        message: '',
                        notice: '',
                        urls: {},
                        quality: 'hd',
                        lastRetry: 0,

                        async ticket(resume) {
                            const response = await fetch(config.ticket + (resume ? '?resume=1' : ''), {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': config.csrf,
                                    'Accept': 'application/json',
                                },
                            });

                            return { response, data: await response.json() };
                        },

                        // Point the player at a link and carry on from the same second.
                        load(url, at, playing) {
                            const player = this.$refs.player;

                            player.src = url;
                            player.addEventListener('loadedmetadata', () => {
                                if (at > 0) {
                                    player.currentTime = at;
                                }
                            }, { once: true });

                            if (playing) {
                                // iOS may refuse a play() that is not straight from a tap;
                                // the visible controls are the way on, so swallow it.
                                player.play().catch(() => {});
                            }
                        },

                        async start() {
                            this.loading = true;
                            this.notice = '';

                            try {
                                const { response, data } = await this.ticket(false);

                                if (!response.ok || !data.ok) {
                                    this.left = 0;
                                    this.locked = true;
                                    this.message = data.message || 'This lesson is no longer available to watch.';
                                    return;
                                }

                                this.urls = data.urls || { hd: data.url };
                                this.quality = data.quality || 'hd';
                                this.left = typeof data.remaining === 'number' ? data.remaining : Math.max(0, this.left - 1);
                                this.started = true;
                                this.load(data.url, 0, true);
                            } catch (e) {
                                this.notice = 'The video could not be started. Check your connection and try again.';
                            } finally {
                                this.loading = false;
                            }
                        },

                        switchQuality(q) {
                            if (!this.urls[q] || q === this.quality) {
                                return;
                            }

                            const player = this.$refs.player;
                            this.quality = q;
                            this.load(this.urls[q], player.currentTime, !player.paused);
                        },

                        toggleFullscreen() {
                            window.etecFullscreen(this.$root, this.$refs.player);
                        },

                        // The video reached its end: that viewing is over (2026-11-11). The
                        // link is dropped so the player cannot simply replay it, and the
                        // button comes back for the next viewing, or the request screen
                        // when none are left.
                        finished() {
                            const player = this.$refs.player;

                            player.removeAttribute('src');
                            player.load();
                            this.started = false;

                            if (this.left <= 0) {
                                this.locked = true;
                                this.message = 'That was your last viewing of this lesson.';
                            }
                        },

                        // ⚠️ A DROPPED CONNECTION OR AN OLD LINK MUST NOT END THE LESSON
                        // (2026-10-01). Ask for a fresh link for the SAME window — resume
                        // never opens a new one, so it can never spend a viewing — and
                        // carry on from the same second. At most once a minute, so a
                        // file that is truly gone does not loop.
                        async recover() {
                            const error = this.$refs.player.error;

                            if (!this.started || this.locked || !error || (error.code !== 2 && error.code !== 4)) {
                                return;
                            }

                            if (Date.now() - this.lastRetry < 60000) {
                                this.notice = 'The video stopped loading. Check your connection, then reload the page.';
                                return;
                            }

                            this.lastRetry = Date.now();
                            const at = this.$refs.player.currentTime;

                            try {
                                const { response, data } = await this.ticket(true);

                                if (!response.ok || !data.ok) {
                                    // The viewing's link ran out. With viewings left, the
                                    // button comes back for the next one; with none, the
                                    // request screen.
                                    this.left = typeof data.remaining === 'number' ? data.remaining : 0;
                                    this.started = false;
                                    this.locked = this.left <= 0;
                                    this.message = data.message || 'This lesson is no longer available to watch.';
                                    this.notice = this.locked ? '' : this.message;
                                    return;
                                }

                                this.urls = data.urls || { hd: data.url };
                                this.load(this.urls[this.quality] || data.url, at, true);
                            } catch (e) {
                                this.notice = 'The video stopped loading. Check your connection, then reload the page.';
                            }
                        },
                    }));
                });
            </script>
    @endonce
                @elseif ($lesson->video_provider === 'zoom' && $lesson->video_url)
                    <div class="flex aspect-video w-full flex-col items-center justify-center gap-3 bg-slate-900 text-white">
                        <svg class="h-10 w-10 text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <p class="text-sm text-white/70">This lesson is a Zoom recording.</p>
                        <a href="{{ $lesson->video_url }}" target="_blank" class="btn-primary">Open Recording</a>
                    </div>
                @endif

                <div class="p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">{{ $lesson->title }}</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $lesson->duration_minutes ? $lesson->duration_minutes.' minutes' : '' }}</p>
                        </div>
                        @if ($completedIds->contains($lesson->id))
                            <span class="badge bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">✓ Completed</span>
                        @else
                            <form method="POST" action="{{ route('student.learn.complete', [$course, $lesson]) }}">
                                @csrf
                                <button class="btn-primary">Mark as Complete</button>
                            </form>
                        @endif
                    </div>

                    @if ($lesson->content)
                        <div class="prose prose-slate mt-5 max-w-none text-sm dark:prose-invert">{!! nl2br(e($lesson->content)) !!}</div>
                    @endif

                    @if ($lesson->materials->isNotEmpty())
                        <div class="mt-6 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">Lesson materials</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($lesson->materials as $material)
                                    <li>
                                        <a href="{{ asset('storage/'.$material->file_path) }}" target="_blank" class="inline-flex items-center gap-2 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            {{ $material->title }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-5 dark:border-slate-800">
                        @if ($previous)
                            <a href="{{ route('student.learn.lesson', [$course, $previous]) }}" class="btn-secondary">← Previous</a>
                        @else
                            <span></span>
                        @endif
                        @if ($next)
                            <a href="{{ route('student.learn.lesson', [$course, $next]) }}" class="btn-primary">Next Lesson →</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Curriculum sidebar --}}
        <div>
            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 p-5 dark:border-slate-800">
                    <h3 class="font-bold text-slate-900 dark:text-white">Course content</h3>
                    <div class="mt-3">
                        <div class="mb-1 flex justify-between text-xs text-slate-400"><span>Progress</span><span class="font-bold text-slate-700 dark:text-slate-200">{{ round($enrollment->progress_percentage) }}%</span></div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $enrollment->progress_percentage }}%"></div>
                        </div>
                    </div>
                </div>
                <div class="max-h-[32rem] overflow-y-auto">
                    @foreach ($course->modules as $module)
                        @if ($module->lessons->isNotEmpty())
                            <div class="border-b border-slate-100 px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate-400 dark:border-slate-800">{{ $module->title }}</div>
                            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($module->lessons as $item)
                                    @include('student._lesson-nav-item', ['item' => $item])
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                    @if ($unassigned->isNotEmpty())
                        @if ($course->modules->isNotEmpty())
                            <div class="border-b border-slate-100 px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate-400 dark:border-slate-800">Other lessons</div>
                        @endif
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($unassigned as $item)
                                @include('student._lesson-nav-item', ['item' => $item])
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
