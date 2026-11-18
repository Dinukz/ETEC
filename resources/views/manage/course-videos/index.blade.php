<x-layouts.app title="Course Videos" header="Course Videos">
<div x-data="{ preview: null, previewName: '' }">
    {{-- ⚠️ ONE PLAYER FOR THE WHOLE PAGE, not one per row. The source is only
         set when a Preview button is pressed, so opening this screen never
         starts 29 downloads. --}}
    <div x-show="preview" x-cloak @keydown.escape.window="preview = null"
        class="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-3 bg-slate-900/80 p-4" style="display:none">
        <p class="text-sm font-medium text-white" x-text="previewName"></p>
        <video x-show="preview" :src="preview" controls autoplay
            class="max-h-[80vh] w-full max-w-4xl rounded-xl bg-black" @click.stop></video>
        <button type="button" @click="preview = null" class="btn-secondary text-sm">Close</button>
    </div>
    <div class="mb-6">
        <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">Course videos</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Upload a video straight into a lesson below, or point a lesson at a file already on the server —
            files placed by FTP into <span class="font-mono text-xs">resources/Videos/&lt;course folder&gt;</span> appear in the list further down.
        </p>
    </div>

    <x-flash />

    @if ($errors->any())
        <div class="mb-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- ================= Upload a video (2026-10-10) =================
         Pick the course, name a new lesson or pick an existing one, choose the
         file. The browser sends it in pieces the server can accept, then the
         server web-optimises it WITHOUT re-encoding (quality unchanged) and
         attaches it. See App\Services\VideoUploads. --}}
    <div class="card mb-6 p-6" x-data="videoUpload({
            lessons: {{ \Illuminate\Support\Js::from($lessonsByCourse) }},
            chunkBytes: {{ (int) $chunkBytes }},
            startUrl: '{{ route('manage.course-videos.upload.start') }}',
            chunkUrl: '{{ route('manage.course-videos.upload.chunk', str_repeat('0', 32)) }}',
            finishUrl: '{{ route('manage.course-videos.upload.finish', str_repeat('0', 32)) }}',
            stepUrl: '{{ route('manage.course-videos.job.step', str_repeat('0', 32)) }}',
            indexUrl: '{{ route('manage.course-videos.index') }}',
        })">
        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Upload a video</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Any size up to 6 GB. It is sent in small pieces, then web-optimised so it starts playing at once —
            the picture is not re-encoded, so the quality stays exactly the same.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="label" for="up-course">Course</label>
                <select id="up-course" class="input text-sm" x-model="courseId" @change="lessonId = ''" :disabled="busy">
                    <option value="">Choose a course…</option>
                    @foreach ($uploadCourses as $course)
                        <option value="{{ $course->id }}">{{ $course->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="up-lesson">Lesson</label>
                <select id="up-lesson" class="input text-sm" x-model="lessonId" :disabled="busy || ! courseId">
                    <option value="">New lesson…</option>
                    <template x-for="lesson in courseLessons" :key="lesson.id">
                        <option :value="lesson.id" x-text="lesson.title + (lesson.has_video ? ' (replace its video)' : '')"></option>
                    </template>
                </select>
            </div>
            <div x-show="lessonId === ''">
                <label class="label" for="up-title">New lesson name</label>
                <input id="up-title" type="text" class="input text-sm" x-model="title" maxlength="180" placeholder="e.g. Day 16" :disabled="busy">
            </div>
            <div>
                <label class="label" for="up-file">Video file</label>
                <input id="up-file" type="file" class="input text-sm" accept=".mp4,.m4v,.mov,video/mp4,video/quicktime" @change="pick($event)" :disabled="busy">
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" class="btn-primary text-sm" @click="upload()" :disabled="busy || ! ready">
                <span x-show="! busy">Upload</span>
                <span x-show="busy" style="display:none">Working…</span>
            </button>
            <p class="text-xs text-slate-400" x-show="file" style="display:none" x-text="fileLabel"></p>
            <p class="text-xs text-amber-600 dark:text-amber-400" x-show="canResume" style="display:none">
                The last upload stopped part-way. Press Upload to carry on — what was already sent is kept.
            </p>
        </div>

        <div class="mt-4" x-show="busy || done || error" style="display:none">
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div class="h-2 rounded-full bg-brand-600" :style="'width:' + percent + '%;transition:width .3s'"></div>
            </div>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300" x-text="status"></p>
            <p class="mt-1 text-sm font-medium text-emerald-600 dark:text-emerald-400" x-show="done" style="display:none" x-text="done"></p>
            <p class="mt-1 text-sm font-medium text-rose-600 dark:text-rose-400" x-show="error" style="display:none" x-text="error"></p>
        </div>
    </div>

    @unless ($libraryExists)
        <div class="card mb-6 border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
            <h3 class="font-bold text-amber-800 dark:text-amber-300">The video folder is not there yet</h3>
            <p class="mt-1 text-sm text-amber-800/90 dark:text-amber-200/90">
                Create <span class="font-mono text-xs">{{ $libraryPath }}</span> on the server and put a folder inside it for each course.
            </p>
        </div>
    @endunless

    {{-- ================= Lesson order ================= --}}
    {{-- Asked for 2026-09-10: "video wala mulata idn anthima wenak videos wala
         than maru krnn puluwn wenn one... admin drag and drop krla dann puluwn
         widiyta." This orders LESSONS, not library files — a file is just a
         file; what students meet in sequence is the lesson. Everything student
         facing already sorts by lessons.sort_order, so writing it is the whole
         feature. --}}
    <div class="card mt-8 p-6">
        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Lesson order</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Pick a course, then drag a lesson up or down. Whatever sits at the top is what
            students meet first. Dragging saves on its own; Save order re-sends the list if
            a save ever fails. Remove takes a lesson off the course entirely.
        </p>

        <form method="GET" action="{{ route('manage.course-videos.index') }}" class="mt-4 flex flex-wrap gap-2">
            <input type="hidden" name="folder" value="{{ $selected }}">
            <input type="hidden" name="req_per" value="{{ $requestPerPage }}">
            @foreach ($requestFilters as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <select name="order_course" class="input text-sm sm:w-64" onchange="this.form.submit()">
                <option value="">Pick a course…</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected($orderCourseId === $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn-secondary text-sm">Show</button></noscript>
        </form>

        @if ($orderCourseId && $orderLessons->isEmpty())
            <p class="mt-4 text-sm text-slate-400">That course has no lessons yet — attach some videos below first.</p>
        @elseif ($orderLessons->isEmpty())
            <p class="mt-4 text-sm text-slate-400">Pick a course above to see its lessons in order.</p>
        @else
            <ul id="lesson-order" class="mt-5 space-y-2"
                data-url="{{ route('manage.course-videos.reorder') }}"
                data-course="{{ $orderCourseId }}"
                data-csrf="{{ csrf_token() }}">
                @foreach ($orderLessons as $lesson)
                    <li draggable="true" data-id="{{ $lesson->id }}"
                        class="flex cursor-move items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                        <svg class="h-4 w-4 shrink-0 text-slate-300 dark:text-slate-600" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M7 4a1 1 0 100 2 1 1 0 000-2zm6 0a1 1 0 100 2 1 1 0 000-2zM7 9a1 1 0 100 2 1 1 0 000-2zm6 0a1 1 0 100 2 1 1 0 000-2zm-6 5a1 1 0 100 2 1 1 0 000-2zm6 0a1 1 0 100 2 1 1 0 000-2z"/>
                        </svg>
                        <span class="w-7 shrink-0 text-xs font-bold text-slate-400" data-position>{{ $loop->iteration }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ $lesson->title }}</span>
                            @if ($lesson->video_path)
                                <span class="block truncate font-mono text-xs text-slate-400">{{ $lesson->video_path }}</span>
                            @else
                                <span class="block text-xs text-amber-600 dark:text-amber-400">No video attached</span>
                            @endif
                        </span>
                        @if ($lesson->status !== 'published')
                            <span class="badge shrink-0 bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">Draft</span>
                        @endif
                        {{-- Deletes the LESSON, never the file. Students stop seeing it
                             at once. Use Delete video on the file row below to erase the
                             .mp4 as well. --}}
                        <button type="submit" form="drop-{{ $lesson->id }}"
                            class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Remove</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" id="lesson-order-save" class="btn-secondary text-sm">Save order</button>
                <p id="lesson-order-note" class="text-xs text-slate-400"></p>
            </div>

            @foreach ($orderLessons as $lesson)
                <form id="drop-{{ $lesson->id }}" method="POST" action="{{ route('manage.course-videos.remove-lesson') }}" class="hidden"
                    onsubmit="return confirm('Remove \'{{ addslashes($lesson->title) }}\' from this course?\n\nStudents will no longer see it, and their watch history for it is lost. The video file stays on the server.')">
                    @csrf
                    <input type="hidden" name="lesson_id" value="{{ $lesson->id }}">
                </form>
            @endforeach
        @endif
    </div>

    {{-- Students waiting to watch something again.

         ⚠️ PAGED (2026-10-10): 497 were waiting and every one loaded on every
         visit. 50 a page by default; the admin can pick 100 / 200 / 300 / 400.
         Tick any (or Select all on this page) and allow or decline them in one
         go — each goes through the same rule as its own button. The tick boxes
         post through form="bulk-requests", because every row already holds its
         own two forms and forms cannot nest.

         ⚠️ SEARCHABLE (2026-11-04): req_q narrows the list (name, email, phone,
         SRN / SCN, lesson, course) and req_from / req_to by the Sri Lanka day it
         was asked; the heading keeps everyone waiting, and the box stays even
         when nothing matches. --}}
    @if ($pendingTotal > 0)
        <div id="requests" class="card mb-6 overflow-hidden border-brand-200 dark:border-brand-500/30"
            x-data="{ picked: 0, recount() { this.picked = this.$root.querySelectorAll('input[name=\'ids[]\']:checked').length } }">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-200 bg-brand-50 p-5 dark:border-brand-500/30 dark:bg-brand-500/10">
                <h3 class="font-bold text-brand-800 dark:text-brand-300">
                    {{ number_format($pendingTotal) }} student(s) asking to watch a lesson again
                </h3>
                <form method="GET" action="{{ route('manage.course-videos.index') }}#requests" class="flex items-center gap-2">
                    <input type="hidden" name="folder" value="{{ $selected }}">
                    @if ($orderCourseId)
                        <input type="hidden" name="order_course" value="{{ $orderCourseId }}">
                    @endif
                    @foreach ($requestFilters as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <label for="req_per" class="text-xs font-medium text-slate-600 dark:text-slate-300">Show</label>
                    <select id="req_per" name="req_per" class="input py-1 text-sm" onchange="this.form.submit()">
                        @foreach ($requestPageSizes as $size)
                            <option value="{{ $size }}" @selected($requestPerPage === $size)>{{ $size }} a page</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn-secondary text-xs">Show</button></noscript>
                </form>
            </div>

            {{-- Search + the Sri Lanka days it was asked (2026-11-04). Both days are
                 inclusive; either may be left empty. --}}
            <form method="GET" action="{{ route('manage.course-videos.index') }}#requests"
                class="flex flex-col gap-3 border-b border-slate-100 px-4 py-3 sm:px-6 lg:flex-row lg:items-end dark:border-slate-800">
                <input type="hidden" name="folder" value="{{ $selected }}">
                @if ($orderCourseId)
                    <input type="hidden" name="order_course" value="{{ $orderCourseId }}">
                @endif
                <input type="hidden" name="req_per" value="{{ $requestPerPage }}">
                <div class="min-w-0 flex-1">
                    <label for="req_q" class="label">Search student</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input id="req_q" type="search" name="req_q" value="{{ $requestSearch }}" placeholder="Name, email, phone, SRN / SCN, course or lesson..." class="input pl-11">
                    </div>
                </div>
                <div>
                    <label for="req_from" class="label">Asked from</label>
                    <input id="req_from" type="date" name="req_from" value="{{ $requestFrom }}" max="{{ now('Asia/Colombo')->toDateString() }}" class="input lg:w-44">
                </div>
                <div>
                    <label for="req_to" class="label">Asked to</label>
                    <input id="req_to" type="date" name="req_to" value="{{ $requestTo }}" max="{{ now('Asia/Colombo')->toDateString() }}" class="input lg:w-44">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary">Search</button>
                    @if ($requestFilters !== [])
                        <a href="{{ route('manage.course-videos.index', ['folder' => $selected, 'order_course' => $orderCourseId ?: null, 'req_per' => $requestPerPage]) }}#requests"
                            class="text-sm font-medium text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">Clear</a>
                    @endif
                </div>
            </form>

            @if ($pendingRequests->total() === 0)
                <p class="px-4 py-8 text-center text-sm text-slate-500 sm:px-6 dark:text-slate-400">
                    No request matches this search.
                </p>
            @else
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-6 dark:border-slate-800">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                        <input type="checkbox" class="rounded border-slate-300"
                            @change="$root.querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = $el.checked); recount()">
                        Select all on this page
                    </label>
                    <span class="text-sm text-slate-500 dark:text-slate-400"><span x-text="picked">0</span> selected</span>
                    <span class="text-xs text-slate-400">
                        Showing {{ $pendingRequests->firstItem() }}–{{ $pendingRequests->lastItem() }} of {{ number_format($pendingRequests->total()) }}{{ $requestFilters !== [] ? ' matching' : '' }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" form="bulk-requests" name="action" value="approve" class="btn-primary text-xs" :disabled="picked === 0"
                        onclick="return confirm('Allow every ticked student to watch that lesson once more?')">
                        Allow selected (<span x-text="picked">0</span>)
                    </button>
                    <button type="submit" form="bulk-requests" name="action" value="decline" class="btn-secondary text-xs" :disabled="picked === 0"
                        onclick="return confirm('Decline every ticked request?')">
                        Decline selected
                    </button>
                </div>
            </div>

            <form id="bulk-requests" method="POST" action="{{ route('manage.course-videos.requests.bulk') }}" class="hidden">@csrf</form>

            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($pendingRequests as $req)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <input type="checkbox" name="ids[]" value="{{ $req->id }}" form="bulk-requests"
                                class="mt-1 rounded border-slate-300" @change="recount()" aria-label="Select {{ $req->user?->name }}">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $req->user?->name ?? 'Unknown' }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $req->lesson?->course?->title ?? 'Course' }} — {{ $req->lesson?->title ?? 'Lesson' }}
                                </p>
                                @if ($req->created_at)
                                    {{-- Sri Lanka time — the day the "Asked from / to" filter goes by. --}}
                                    <p class="text-xs text-slate-400">Asked {{ $req->created_at->copy()->timezone('Asia/Colombo')->format('j M Y, g:i A') }}</p>
                                @endif
                                @if (filled($req->reason))
                                    <p class="mt-1 max-w-lg text-xs italic text-slate-400">“{{ $req->reason }}”</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <form method="POST" action="{{ route('manage.course-videos.approve', $req) }}">
                                @csrf
                                <button type="submit" class="btn-primary text-xs">Allow again</button>
                            </form>
                            <form method="POST" action="{{ route('manage.course-videos.decline', $req) }}">
                                @csrf
                                <button type="submit" class="btn-secondary text-xs">Decline</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($pendingRequests->hasPages())
                <div class="border-t border-slate-100 px-4 py-3 sm:px-6 dark:border-slate-800">{{ $pendingRequests->links() }}</div>
            @endif
            @endif
        </div>
    @endif

    @if ($folders->isNotEmpty())
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($folders as $folder)
                <a href="{{ route('manage.course-videos.index', ['folder' => $folder['name'], 'req_per' => $requestPerPage] + $requestFilters) }}"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $folder['name'] === $selected ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300' }}">
                    {{ $folder['name'] }}
                    <span class="opacity-70">({{ $folder['files']->count() }})</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($files->isEmpty())
        <div class="card p-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                No video files in this folder yet. Put <span class="font-mono text-xs">.mp4</span> files into
                <span class="font-mono text-xs">resources/Videos/{{ $selected }}</span> and reload.
            </p>
        </div>
    @else
        {{-- ⚠️ BULK FIRST. One course here is 29 files; doing them one at a time
             is 29 round trips. Tick what you want and press once.

             The form WRAPS the table, so the per-row "Attach" forms below cannot
             be nested inside it — HTML forbids nested forms and the browser
             silently drops the inner one. The per-row controls therefore post
             with the `form=` attribute instead, targeting their own form
             element rendered after the table. --}}
        <form method="POST" action="{{ route('manage.course-videos.attach-many') }}" x-data="{ picked: 0 }"
            @change="picked = $el.querySelectorAll('input[name=\'paths[]\']:checked').length">
            @csrf

            <div class="card mb-4 flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                        <input type="checkbox" class="rounded border-slate-300"
                            @change="$el.closest('form').querySelectorAll('input[name=\'paths[]\']').forEach(c => c.checked = $el.checked)">
                        Select all
                    </label>
                    <span class="text-sm text-slate-500 dark:text-slate-400">
                        <span x-text="picked">0</span> selected
                    </span>
                    @php $notOptimised = $files->filter(fn ($f) => ($f['faststart'] ?? null) === false)->pluck('path')->values(); @endphp
                    @if ($notOptimised->isNotEmpty())
                        <span x-data="videoOptimise()" class="flex items-center gap-2">
                            <button type="button" class="btn-secondary text-xs" x-show="! busy" @click="runAll({{ \Illuminate\Support\Js::from($notOptimised) }})">
                                Web-optimise all {{ $notOptimised->count() }} in this folder
                            </button>
                            <span x-show="busy || msg" style="display:none" class="text-xs text-slate-500 dark:text-slate-400" x-text="msg"></span>
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select name="course_id" required class="input text-sm sm:w-56">
                        <option value="">Add them to which course?</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->title }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-primary text-sm" :disabled="picked === 0">
                        Create lessons for selected
                    </button>
                </div>
            </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th class="w-10 px-5 py-3 sm:pl-6"><span class="sr-only">Select</span></th>
                            <th class="px-5 py-3 font-semibold">File</th>
                            <th class="px-5 py-3 font-semibold">Size</th>
                            <th class="px-5 py-3 font-semibold">Plays as</th>
                            <th class="px-5 py-3 font-semibold">Attach to a lesson</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($files as $file)
                            @php $lesson = $attached[$file['path']] ?? null; @endphp
                            <tr>
                                <td class="px-5 py-3 sm:pl-6">
                                    <input type="checkbox" name="paths[]" value="{{ $file['path'] }}"
                                        class="rounded border-slate-300" @disabled($lesson !== null)>
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $file['name'] }}</p>
                                    <p class="font-mono text-xs text-slate-400">{{ $file['path'] }}</p>
                                    {{-- Plays through the admin preview route, which is gated on
                                         the manage role rather than an enrollment and consumes no
                                         student viewing window. See CourseVideoController::preview(). --}}
                                    <button type="button"
                                        @click="preview = '{{ route('manage.course-videos.preview', ['path' => $file['path']]) }}'; previewName = @js($file['name'])"
                                        class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Preview
                                    </button>
                                    @unless ($lesson)
                                        <p class="mt-0.5 text-xs text-slate-400">Will be named “{{ \App\Services\VideoLibrary::suggestTitle($file['path']) }}”</p>
                                    @endunless
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                    {{ \App\Services\VideoLibrary::humanSize($file['size']) }}
                                    {{-- ⚠️ Which files make students wait (2026-10-01) — read
                                         from the MP4 headers, see App\Support\Mp4Probe. --}}
                                    @if (($file['duration'] ?? null) !== null)
                                        <p class="text-xs text-slate-400">
                                            {{ gmdate($file['duration'] >= 3600 ? 'G\h i\m' : 'i\m s\s', (int) $file['duration']) }}
                                            · {{ number_format(($file['bps'] ?? 0) / 1000000, 1) }} Mbit/s
                                        </p>
                                    @endif
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if ($file['heavy'] ?? false)
                                            <span class="badge whitespace-nowrap bg-amber-50 text-amber-700" title="Above 2.5 Mbit/s — slow on mobile data. Make a smaller copy with HandBrake and put it in an SD folder beside this file.">Heavy — add an SD copy</span>
                                        @endif
                                        @if (($file['faststart'] ?? null) === false)
                                            <span class="badge whitespace-nowrap bg-amber-50 text-amber-700" title="The index is at the end of the file, so playback cannot start until it is fetched. HandBrake's Web Optimized tick fixes it.">Not web-optimised</span>
                                        @endif
                                        @if ($file['light'] ?? null)
                                            <span class="badge whitespace-nowrap bg-emerald-50 text-emerald-600" title="{{ $file['light'] }}">SD copy ✓</span>
                                        @endif
                                    </div>
                                    {{-- Fixes "Not web-optimised" where the file lies, without
                                         re-encoding — see App\Support\Mp4FastStart. --}}
                                    @if (($file['faststart'] ?? null) === false)
                                        <div class="mt-1" x-data="videoOptimise()">
                                            <button type="button" x-show="! busy" @click="run(@js($file['path']))"
                                                class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Make web-optimised</button>
                                            <p x-show="busy || msg" style="display:none" class="text-xs text-slate-500 dark:text-slate-400" x-text="msg"></p>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($lesson)
                                        <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ $lesson->title }}</p>
                                        <p class="text-xs text-slate-400">{{ $lesson->course?->title }}</p>
                                        <button type="submit" form="detach-{{ $loop->index }}"
                                            class="mt-1 text-xs font-medium text-rose-600 hover:underline dark:text-rose-400">Remove</button>
                                    @else
                                        <span class="text-xs text-slate-400">Not attached</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    {{-- form="…" rather than a nested <form>: this table sits
                                         inside the bulk form above, and a nested form is dropped
                                         by every browser. --}}
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <input type="hidden" name="video_path" value="{{ $file['path'] }}" form="row-{{ $loop->index }}">
                                        {{-- Carrying lesson_id is what makes this a RENAME rather
                                             than a second lesson pointing at the same file. --}}
                                        @if ($lesson)
                                            <input type="hidden" name="lesson_id" value="{{ $lesson->id }}" form="row-{{ $loop->index }}">
                                        @endif
                                        <select name="course_id" required class="input text-xs sm:w-40" form="row-{{ $loop->index }}">
                                            <option value="">Course…</option>
                                            @foreach ($courses as $course)
                                                <option value="{{ $course->id }}" @selected($lesson?->course_id === $course->id)>{{ $course->title }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="title" class="input text-xs sm:w-44" placeholder="Lesson name"
                                            form="row-{{ $loop->index }}"
                                            value="{{ $lesson?->title ?? \App\Services\VideoLibrary::suggestTitle($file['path']) }}">
                                        <button type="submit" class="btn-secondary text-xs" form="row-{{ $loop->index }}">{{ $lesson ? 'Rename' : 'Attach' }}</button>
                        {{-- The only control that removes a video for students AND clears
                             the disk. Detach above just unhooks it, which left the lesson
                             on the student's sidebar playing nothing (2026-09-11). --}}
                        <button type="submit" form="destroy-{{ $loop->index }}"
                            class="btn-secondary text-xs !text-rose-600 dark:!text-rose-400">Delete video</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        </form>

        {{-- The per-row forms live here, outside the bulk form, and are reached
             by the form="…" attributes above. --}}
        @foreach ($files as $file)
            @php $rowLesson = $attached[$file['path']] ?? null; @endphp
            <form id="row-{{ $loop->index }}" method="POST" action="{{ route('manage.course-videos.attach') }}" class="hidden">@csrf</form>
            <form id="destroy-{{ $loop->index }}" method="POST" action="{{ route('manage.course-videos.destroy') }}" class="hidden"
                onsubmit="return confirm('Delete {{ addslashes($file['name']) }} from the server?\n\nThis erases the video file itself and removes every lesson that plays it. Students lose their watch history for it. It cannot be undone.')">
                @csrf
                <input type="hidden" name="video_path" value="{{ $file['path'] }}">
                <input type="hidden" name="confirm" value="1">
            </form>
            @if ($rowLesson)
                <form id="detach-{{ $loop->index }}" method="POST" action="{{ route('manage.course-videos.detach') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="lesson_id" value="{{ $rowLesson->id }}">
                </form>
            @endif
        @endforeach
    @endif

</div>

{{-- ⚠️ UPLOAD + WEB-OPTIMISE (2026-10-10). Inline, registered on alpine:init —
     this layout has no @stack, and @vite emits a DEFERRED module, so this runs
     before Alpine starts (same as student/learn.blade.php).

     Every request is small and can be repeated: a piece the server already has
     is skipped, an out-of-order piece is answered with where to carry on, and
     an optimising step that times out is simply asked for again. --}}
<script>
    document.addEventListener('alpine:init', () => {
        const ZERO = '00000000000000000000000000000000';
        const csrf = () => document.querySelector('meta[name=csrf-token]').content;
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

        async function call(url, body) {
            const isForm = body instanceof FormData;
            const headers = { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' };

            if (! isForm) {
                headers['Content-Type'] = 'application/json';
            }

            const response = await fetch(url, { method: 'POST', headers, body: isForm ? body : JSON.stringify(body || {}) });
            let data = {};

            try {
                data = await response.json();
            } catch (e) {
                data = {};
            }

            return { ok: response.ok, status: response.status, data };
        }

        function messageOf(r) {
            if (r.status === 419) {
                return 'Your session expired. Reload the page and start again.';
            }

            if (r.data && r.data.errors) {
                return Object.values(r.data.errors).flat().join(' ');
            }

            return (r.data && r.data.message) || ('The server answered ' + r.status + '.');
        }

        // ⚠️ ONLY A REAL HICCUP IS RETRIED (review 2026-10-10). No answer, a
        // busy host (408/429/502/503/504/508 and Cloudflare-style 52x), or an
        // error page the HOST served instead of the app (no JSON message) —
        // those pass. A JSON answer with a message is the server saying what
        // is wrong ("the disk is full"), and is shown, not hidden behind
        // "the connection keeps dropping".
        function transient(r) {
            if (! r) {
                return true;
            }

            if ([408, 429, 502, 503, 504, 508, 520, 521, 522, 524].includes(r.status)) {
                return true;
            }

            return r.status >= 500 && ! (r.data && r.data.message);
        }

        async function withRetries(send, tries, onWait) {
            for (let attempt = 1; ; attempt++) {
                let r = null;

                try {
                    r = await send();
                } catch (e) {
                    r = null;
                }

                if (! transient(r)) {
                    return r;
                }

                if (attempt >= tries) {
                    throw new Error('The connection keeps dropping. Check the internet, then press the button again — it carries on from where it stopped.');
                }

                if (onWait) {
                    onWait();
                }

                await sleep(Math.min(1500 * attempt, 15000));
            }
        }

        // Keep asking for the next optimising step until the job is finished.
        // Pressing the button again later picks the same job up — nothing is
        // copied twice.
        async function runJob(stepUrl, job, onProgress) {
            while (true) {
                const r = await withRetries(() => call(stepUrl.replace(ZERO, job)), 8);

                if (! r.ok) {
                    throw new Error(messageOf(r));
                }

                if (r.data.done) {
                    if (r.data.failed) {
                        throw new Error(r.data.message);
                    }

                    return r.data;
                }

                onProgress(r.data.progress || 0);
            }
        }

        const optimiseUrl = @js(route('manage.course-videos.optimise'));
        const stepUrl = @js(route('manage.course-videos.job.step', str_repeat('0', 32)));

        Alpine.data('videoUpload', (cfg) => ({
            courseId: '',
            lessonId: '',
            title: '',
            file: null,
            busy: false,
            finished: false,
            percent: 0,
            status: '',
            done: '',
            error: '',
            // An upload that stopped part-way is carried on, not sent again.
            uploadId: null,
            uploadKey: '',

            get courseLessons() {
                return cfg.lessons[this.courseId] || [];
            },

            get ready() {
                return ! this.finished && this.courseId && this.file && (this.lessonId !== '' || this.title.trim() !== '');
            },

            get fileLabel() {
                return this.file ? this.file.name + ' · ' + (this.file.size / 1048576).toFixed(1) + ' MB' : '';
            },

            get canResume() {
                return ! this.busy && this.uploadId !== null && this.file !== null && this.uploadKey === this.keyOf();
            },

            keyOf() {
                return this.file
                    ? [this.file.name, this.file.size, this.file.lastModified, this.courseId, this.lessonId, this.title.trim()].join('|')
                    : '';
            },

            pick(event) {
                this.file = event.target.files[0] || null;
                this.done = '';
                this.error = '';

                if (this.file && this.lessonId === '' && this.title.trim() === '') {
                    this.title = this.file.name.replace(/\.[^.]+$/, '');
                }
            },

            async upload() {
                if (! this.ready || this.busy) {
                    return;
                }

                this.busy = true;
                this.done = '';
                this.error = '';
                this.percent = 0;

                const guard = (e) => { e.preventDefault(); e.returnValue = ''; };
                window.addEventListener('beforeunload', guard);

                try {
                    let id = this.canResumeNow() ? this.uploadId : null;
                    let piece = cfg.chunkBytes;
                    const resuming = id !== null;

                    if (resuming) {
                        this.status = 'Carrying on from where it stopped…';
                    } else {
                        this.status = 'Starting…';

                        const started = await withRetries(() => call(cfg.startUrl, {
                            course_id: Number(this.courseId),
                            lesson_id: this.lessonId === '' ? null : Number(this.lessonId),
                            title: this.lessonId === '' ? this.title.trim() : null,
                            file_name: this.file.name,
                            file_size: this.file.size,
                        }), 4);

                        if (! started.ok) {
                            throw new Error(messageOf(started));
                        }

                        id = started.data.id;
                        piece = started.data.chunk_bytes || piece;
                        this.uploadId = id;
                        this.uploadKey = this.keyOf();
                    }

                    // From 0 even on a resume: the server answers with how much
                    // it already holds, and the loop jumps there.
                    const size = this.file.size;
                    let offset = 0;

                    while (offset < size) {
                        const form = new FormData();
                        form.append('offset', offset);
                        form.append('chunk', this.file.slice(offset, offset + piece), 'piece.bin');

                        const r = await withRetries(
                            () => call(cfg.chunkUrl.replace(ZERO, id), form),
                            9,
                            () => { this.status = 'Connection dropped — trying again…'; },
                        );

                        if (r.ok) {
                            offset = r.data.received;
                        } else if (r.status === 409 && typeof r.data.received === 'number') {
                            offset = r.data.received;
                        } else if (r.status === 409) {
                            break; // every piece is already in — straight on to finishing
                        } else if (r.status === 404 && resuming) {
                            this.uploadId = null;
                            throw new Error('The earlier upload had expired on the server. Press Upload again to send it from the start.');
                        } else {
                            throw new Error(messageOf(r));
                        }

                        this.percent = Math.floor(offset / size * 90);
                        this.status = 'Uploading… ' + Math.floor(offset / size * 100) + '%';
                    }

                    this.status = 'Checking the video…';

                    // Safe to repeat: the server answers a second finish with
                    // the result of the first.
                    const finished = await withRetries(() => call(cfg.finishUrl.replace(ZERO, id)), 6);

                    if (! finished.ok) {
                        if (finished.status === 404) {
                            this.uploadId = null;
                        }

                        throw new Error(messageOf(finished));
                    }

                    let result = finished.data;

                    if (! result.done) {
                        this.status = 'Web-optimising (quality unchanged)… 0%';
                        result = await runJob(cfg.stepUrl, result.job, (p) => {
                            this.percent = 90 + Math.floor(p * 10);
                            this.status = 'Web-optimising (quality unchanged)… ' + Math.floor(p * 100) + '%';
                        });
                    }

                    // ⚠️ Stays locked until the page reloads — a second press
                    // here would upload the same file as a second lesson.
                    this.finished = true;
                    this.uploadId = null;
                    this.percent = 100;
                    this.status = 'Done.';
                    this.done = result.message;

                    window.removeEventListener('beforeunload', guard);
                    const folder = (result.path || '').split('/')[0];
                    setTimeout(() => { window.location.href = cfg.indexUrl + (folder ? '?folder=' + encodeURIComponent(folder) : ''); }, 2500);
                } catch (e) {
                    this.error = e.message || 'The upload stopped.';
                    this.status = '';
                } finally {
                    window.removeEventListener('beforeunload', guard);

                    if (! this.finished) {
                        this.busy = false;
                    }
                }
            },

            // canResume minus the busy check — upload() has already set busy.
            canResumeNow() {
                return this.uploadId !== null && this.file !== null && this.uploadKey === this.keyOf();
            },
        }));

        Alpine.data('videoOptimise', () => ({
            busy: false,
            msg: '',

            async optimiseOne(path, label) {
                const r = await withRetries(() => call(optimiseUrl, { video_path: path }), 4);

                if (! r.ok) {
                    throw new Error(messageOf(r));
                }

                if (r.data.done) {
                    return r.data.message;
                }

                const result = await runJob(stepUrl, r.data.job, (p) => {
                    this.msg = label + Math.floor(p * 100) + '%';
                });

                return result.message;
            },

            async run(path) {
                if (! confirm('Make this video web-optimised?\n\nThe picture is copied exactly — no quality is lost. Anyone watching it right now may need to reload the lesson.')) {
                    return;
                }

                this.busy = true;
                this.msg = 'Optimising… 0%';

                try {
                    this.msg = await this.optimiseOne(path, 'Optimising… ');
                    setTimeout(() => window.location.reload(), 1500);
                } catch (e) {
                    this.msg = e.message;
                    this.busy = false;
                }
            },

            async runAll(paths) {
                if (! confirm('Web-optimise all ' + paths.length + ' videos in this folder?\n\nThe picture is copied exactly — no quality is lost. Keep this page open until it finishes.')) {
                    return;
                }

                this.busy = true;
                const failures = [];

                for (let i = 0; i < paths.length; i++) {
                    const label = (i + 1) + ' of ' + paths.length + ' — ';

                    try {
                        await this.optimiseOne(paths[i], label);
                    } catch (e) {
                        failures.push(paths[i].split('/').pop() + ': ' + e.message);
                    }
                }

                // Every reason is shown, and the page is NOT reloaded over it.
                if (failures.length) {
                    this.msg = (paths.length - failures.length) + ' done. Not optimised — ' + failures.join(' · ');
                    this.busy = false;

                    return;
                }

                this.msg = 'All done.';
                setTimeout(() => window.location.reload(), 2000);
            },
        }));
    });
</script>

@if ($orderLessons->isNotEmpty())
    {{-- ⚠️ Native HTML5 drag events, no library. A vertical list does not need
         SortableJS, and adding it would mean an npm install plus a bigger
         bundle for one screen. Vanilla, not Alpine, so it cannot be affected by
         the single shared Alpine instance this build bundles through Livewire. --}}
    <script>
        (function () {
            var list = document.getElementById('lesson-order');

            if (! list) {
                return;
            }

            var note = document.getElementById('lesson-order-note');
            var dragging = null;

            list.addEventListener('dragstart', function (e) {
                dragging = e.target.closest('li');

                if (dragging) {
                    dragging.style.opacity = '0.4';
                }
            });

            var saveButton = document.getElementById('lesson-order-save');

            if (saveButton) {
                saveButton.addEventListener('click', function () {
                    renumber();
                    save();
                });
            }

            list.addEventListener('dragend', function () {
                if (dragging) {
                    dragging.style.opacity = '';
                }

                dragging = null;
                renumber();
                save();
            });

            list.addEventListener('dragover', function (e) {
                e.preventDefault();

                var over = e.target.closest('li');

                if (! over || ! dragging || over === dragging) {
                    return;
                }

                var box = over.getBoundingClientRect();
                var after = (e.clientY - box.top) > (box.height / 2);

                list.insertBefore(dragging, after ? over.nextSibling : over);
            });

            function renumber() {
                Array.prototype.forEach.call(list.querySelectorAll('li'), function (li, i) {
                    var cell = li.querySelector('[data-position]');

                    if (cell) {
                        cell.textContent = i + 1;
                    }
                });
            }

            function save() {
                var ids = Array.prototype.map.call(list.querySelectorAll('li'), function (li) {
                    return li.dataset.id;
                });

                note.textContent = 'Saving…';

                fetch(list.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': list.dataset.csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ course_id: list.dataset.course, lesson_ids: ids }),
                })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                    .then(function (data) { note.textContent = data.message || 'Saved.'; })
                    .catch(function () { note.textContent = 'Could not save the order — reload and try again.'; });
            }
        })();
    </script>
@endif
</x-layouts.app>
