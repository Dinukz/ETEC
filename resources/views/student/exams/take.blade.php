<x-layouts.app :title="$exam->title" header="Paper in Progress">
    {{-- ⚠️ THE CLOCK IS THE SERVER'S (2026-10-18). The countdown starts from the
         seconds the server says are left, not from this device's clock, and
         every answer is saved as it is picked (examSheet.save). When time is up
         the server marks what was saved before the deadline — closing the tab
         or sending answers late changes nothing. See App\Services\ExamGrader. --}}
    <div class="mx-auto max-w-3xl" x-data="examSheet({
            secondsLeft: {{ (int) $secondsLeft }},
            saveUrl: '{{ route('student.exams.save', $exam) }}',
        })">
        <div class="card sticky top-20 z-20 mb-6 flex flex-wrap items-center justify-between gap-3 p-4">
            <div>
                <h2 class="font-bold text-slate-900 dark:text-white">{{ $exam->title }}</h2>
                <p class="text-xs text-slate-400">{{ $exam->questions->count() }} questions · {{ $exam->total_marks }} marks · pass {{ $exam->pass_marks }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-400">Time remaining</p>
                <p class="text-xl font-extrabold" :class="secondsLeft < 300 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white'" x-text="display"></p>
                <p class="text-xs text-slate-400" x-text="saveNote"></p>
            </div>
        </div>

        <form id="exam-form" method="POST" action="{{ route('student.exams.submit', $exam) }}" class="space-y-5" @change="save()">
            @csrf
            @foreach ($exam->questions as $i => $question)
                <div class="card p-6">
                    <div class="flex items-start gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-sm font-bold text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-slate-900 dark:text-white">{{ $question->question }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $question->marks }} {{ \Illuminate\Support\Str::plural('mark', $question->marks) }}</p>

                            @if ($question->type === 'essay')
                                <textarea name="answers[{{ $question->id }}]" rows="5" class="input mt-3" placeholder="Write your answer..." @input.debounce.1500ms="save()">{{ $saved[$question->id] ?? '' }}</textarea>
                            @else
                                <div class="mt-3 space-y-2">
                                    @foreach ($question->options ?? [] as $n => $option)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-700 transition hover:border-brand-400 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:border-slate-700 dark:text-slate-200 dark:has-[:checked]:bg-brand-500/10">
                                            <input type="radio" name="answers[{{ $question->id }}]" value="{{ $option }}" class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600"
                                                @checked(isset($saved[$question->id]) && strcasecmp((string) $saved[$question->id], (string) $option) === 0)>
                                            <span><span class="font-bold">{{ \App\Models\ExamQuestion::letter($n) }}.</span> {{ $option }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif

                        </div>
                    </div>
                </div>
            @endforeach

            <div class="card flex flex-wrap items-center justify-between gap-3 p-5">
                <p class="text-sm text-slate-500 dark:text-slate-400">Your answers are saved as you go. When the time runs out the paper is handed in by itself.</p>
                <button type="submit" class="btn-primary" onclick="return confirm('Submit your paper? You cannot change answers afterwards.')">Submit Paper</button>
            </div>
        </form>
    </div>

    <script>
        function examSheet(cfg) {
            return {
                secondsLeft: cfg.secondsLeft,
                endsAt: Date.now() + cfg.secondsLeft * 1000,
                display: '--:--',
                saveNote: '',
                submitted: false,
                init() {
                    this.tick();
                    setInterval(() => this.tick(), 1000);
                },
                tick() {
                    this.secondsLeft = Math.max(0, Math.round((this.endsAt - Date.now()) / 1000));
                    const h = Math.floor(this.secondsLeft / 3600);
                    const m = Math.floor((this.secondsLeft % 3600) / 60);
                    const s = this.secondsLeft % 60;
                    this.display = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');

                    if (this.secondsLeft === 0 && ! this.submitted) {
                        this.submitted = true;
                        document.getElementById('exam-form').submit();
                    }
                },
                answers() {
                    const form = document.getElementById('exam-form');
                    const out = {};

                    new FormData(form).forEach((value, key) => {
                        const m = key.match(/^answers\[(\d+)\]$/);

                        if (m) {
                            out[m[1]] = value;
                        }
                    });

                    return out;
                },
                async save() {
                    if (this.submitted) {
                        return;
                    }

                    this.saveNote = 'Saving…';

                    try {
                        const response = await fetch(cfg.saveUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ answers: this.answers() }),
                        });

                        this.saveNote = response.ok ? 'All answers saved' : 'Not saved — time may be up';
                    } catch (e) {
                        this.saveNote = 'Not saved — check the connection';
                    }
                },
            };
        }
    </script>
</x-layouts.app>
