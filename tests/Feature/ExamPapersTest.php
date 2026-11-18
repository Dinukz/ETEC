<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\User;
use App\Services\ExamSchemaRepair;
use App\Support\ExamPaperImport;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ EXAM PAPERS (2026-10-18).
 *
 * *"menn me exam papers tika dagann one, adminta access ekak denn paper dann
 * saha mewage ewa time ekak athule krana widiyt hadal denn. lecturersta me
 * submit krana papers wala mark blann puluwn wennth one, adminta blann puluwn
 * wennth one."*
 *
 * The owner's two papers — the Digital Marketing Lesson 01 quiz (an HTML file
 * with an explanation for every option and a hint per question) and the AI &
 * Prompting Lesson 02 paper (a PDF, typed into docs/exam-papers) — must import
 * whole; admin must reach the papers; a paper is sat within a time limit THE
 * SERVER enforces; and lecturers (their own courses) and admin (all) see marks.
 */
class ExamPapersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lecturer;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        ExamSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());

        $this->admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->lecturer = User::where('email', 'lecturer@etec.lk')->firstOrFail();
        $this->student = User::where('email', 'student@etec.lk')->firstOrFail();

        $template = Course::where('status', 'published')->firstOrFail();
        $this->course = Course::create([
            'title' => 'Exam Test '.Str::random(5), 'slug' => 'exam-test-'.Str::random(6), 'description' => 'x',
            'price' => $template->price, 'currency' => 'LKR', 'status' => 'published',
            'lecturer_id' => $this->lecturer->id, 'published_at' => now(),
        ]);

        $this->enrol($this->student);
    }

    private function enrol(User $user): void
    {
        Enrollment::create(['user_id' => $user->id, 'course_id' => $this->course->id, 'status' => 'active', 'enrolled_at' => now()]);
    }

    private function exam(array $overrides = []): Exam
    {
        return Exam::create($overrides + [
            'course_id' => $this->course->id, 'lecturer_id' => $this->lecturer->id, 'title' => 'Lesson 02 Paper',
            'type' => 'quiz', 'total_marks' => 100, 'pass_marks' => 40, 'duration_minutes' => 10, 'is_published' => false,
        ]);
    }

    private function importPaper(Exam $exam, string $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('manage.exams.import', $exam), [
            'paper' => UploadedFile::fake()->createWithContent(basename($file), file_get_contents($file)),
            'marks_each' => 1,
            'match_total' => 1,
        ]);
    }

    /** The AI & Prompting paper, imported and published. */
    private function publishedPaper(array $overrides = []): Exam
    {
        $exam = $this->exam($overrides);
        $this->importPaper($exam, base_path('docs/exam-papers/AI_Prompting_Lesson02.txt'))->assertSessionHasNoErrors();
        $exam->update(['is_published' => true]);

        return $exam->fresh('questions');
    }

    /** Right answers for the AI paper keyed by question id: B, B, A, D, B. */
    private function rightAnswers(Exam $exam, int $howMany = 5): array
    {
        $answers = [];

        foreach ($exam->questions()->orderBy('sort_order')->take($howMany)->get() as $q) {
            $answers[$q->id] = $q->correct_answer;
        }

        return $answers;
    }

    private function wrongAnswer($question): string
    {
        return collect($question->options)->first(fn ($o) => $o !== $question->correct_answer);
    }

    // ----- the owner's two papers import whole ------------------------------------

    public function test_the_digital_marketing_quiz_file_imports_with_every_explanation_and_hint(): void
    {
        $exam = $this->exam();

        $this->importPaper($exam, base_path('tests/Fixtures/exam-papers/digital-marketing-lesson01.html'))
            ->assertSessionHas('success', '5 question(s) imported. Check them below, then publish the paper.');

        $questions = $exam->questions()->orderBy('sort_order')->get();

        $this->assertCount(5, $questions);
        $this->assertSame(['D', 'C', 'A', 'C', 'D'], $questions->map(fn ($q) => \App\Models\ExamQuestion::letter($q->correctIndex()))->all());
        $this->assertSame('Attention, Interest, Desire, Action', $questions[2]->correct_answer);
        $this->assertCount(4, $questions[0]->option_explanations);
        $this->assertStringContainsString('SEO යනු Inbound Marketing', $questions[0]->option_explanations[0]);
        $this->assertSame('Google සමාගම විසින් ලෝකයට හඳුන්වා දුන් "Zero Moment..." සංකල්පය ගැන මතක් කරගන්න.', $questions[1]->hint);

        // The paper's total follows its questions; the pass mark keeps its share.
        $this->assertSame([5, 2], [$exam->fresh()->total_marks, $exam->fresh()->pass_marks]);
    }

    public function test_the_ai_prompting_paper_imports_with_its_answer_sheet(): void
    {
        $exam = $this->exam();
        $this->importPaper($exam, base_path('docs/exam-papers/AI_Prompting_Lesson02.txt'))->assertSessionHasNoErrors();

        $questions = $exam->questions()->orderBy('sort_order')->get();

        $this->assertSame(['B', 'B', 'A', 'D', 'B'], $questions->map(fn ($q) => \App\Models\ExamQuestion::letter($q->correctIndex()))->all());
        $this->assertSame('Context, Task, Format, Tone', $questions[1]->correct_answer);
        $this->assertStringStartsWith('"Garbage In, Garbage Out"', $questions[0]->question);
        $this->assertStringContainsString('Zero-shot Prompting ලෙස හැඳින්වේ', $questions[2]->explanation);
    }

    public function test_a_paper_with_a_question_missing_its_answer_adds_nothing(): void
    {
        $exam = $this->exam();

        $this->actingAs($this->admin)->post(route('manage.exams.import', $exam), [
            'content' => "1. First?\nA. yes\nB. no\nAnswer: A\n\n2. Second?\nA. one\nB. two\n",
            'marks_each' => 1,
        ])->assertSessionHasErrors(['paper' => 'Question 2 has no correct answer marked.']);

        $this->assertSame(0, $exam->questions()->count(), 'all or nothing');
    }

    public function test_json_in_the_simple_shape_imports_too(): void
    {
        $questions = ExamPaperImport::parse(json_encode([
            ['question' => 'Two plus two?', 'options' => ['3', '4', '5'], 'answer' => 'B', 'hint' => 'Count'],
        ]));

        $this->assertSame(1, $questions[0]['correct']);
        $this->assertSame('Count', $questions[0]['hint']);
    }

    public function test_the_same_option_twice_is_refused(): void
    {
        $this->expectExceptionMessage('Question 1 has two options that are the same.');

        ExamPaperImport::parse("1. Pick\nA. same\nB. Same\nAnswer: A");
    }

    // ----- admin reaches the papers; a lecturer only their own --------------------

    public function test_admin_and_super_admin_have_exams_in_their_menu(): void
    {
        foreach (['admin@etec.lk', 'superadmin@etec.lk'] as $email) {
            $labels = array_column(Navigation::for(User::where('email', $email)->firstOrFail()), 'label');
            $this->assertContains('Exams', $labels, $email);
        }

        $this->actingAs($this->admin)->get(route('manage.exams.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('manage.exams.edit', $this->exam()))->assertOk()->assertSee('Import a paper');
    }

    public function test_another_lecturer_cannot_touch_this_courses_paper(): void
    {
        $other = User::factory()->create();
        $other->syncRoles(['lecturer']);
        $exam = $this->exam();

        $this->actingAs($other)->get(route('manage.exams.edit', $exam))->assertForbidden();
        $this->importPaper($exam, base_path('docs/exam-papers/AI_Prompting_Lesson02.txt'), $other)->assertForbidden();
        $this->actingAs($other)->get(route('manage.exams.results', $exam))->assertForbidden();
    }

    // ----- building a question by hand ---------------------------------------------

    public function test_the_right_answer_is_picked_not_retyped(): void
    {
        $exam = $this->exam();

        $this->actingAs($this->lecturer)->post(route('manage.questions.store', $exam), [
            'question' => 'Which is the funnel top?', 'type' => 'mcq', 'marks' => 2,
            'options' => ['BOFU', 'TOFU', 'MOFU', ''], 'correct' => 1,
            'option_explanations' => ['bottom', 'top — awareness', '', ''],
            'hint' => 'T is for top', 'explanation' => 'TOFU is the top.',
        ])->assertSessionHasNoErrors();

        $q = $exam->questions()->firstOrFail();
        $this->assertSame(['BOFU', 'TOFU', 'MOFU'], $q->options);
        $this->assertSame('TOFU', $q->correct_answer);
        $this->assertSame(['bottom', 'top — awareness', null], $q->option_explanations);
        $this->assertSame('T is for top', $q->hint);

        $this->actingAs($this->lecturer)->patch(route('manage.questions.update', $q), [
            'question' => 'Which is the funnel top?', 'type' => 'mcq', 'marks' => 2,
            'options' => ['BOFU', 'TOFU', 'MOFU'], 'correct' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertSame('MOFU', $q->fresh()->correct_answer);
    }

    public function test_a_question_without_a_picked_answer_is_refused(): void
    {
        $this->actingAs($this->lecturer)->post(route('manage.questions.store', $this->exam()), [
            'question' => 'Q?', 'type' => 'mcq', 'marks' => 1, 'options' => ['a', 'b'],
        ])->assertSessionHasErrors('correct');
    }

    // ----- sitting a paper within the time ------------------------------------------

    public function test_a_student_sits_the_paper_and_sees_the_marked_answers(): void
    {
        $exam = $this->publishedPaper();

        $this->actingAs($this->student)->get(route('student.exams.take', $exam))
            ->assertOk()
            ->assertSee('A.</span> Zero-shot Prompting', false)
            ->assertSee('Time remaining');

        $answers = $this->rightAnswers($exam, 4);
        $last = $exam->questions()->orderByDesc('sort_order')->first();
        $answers[$last->id] = $this->wrongAnswer($last);

        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $answers])
            ->assertRedirect(route('student.exams.review', $exam))
            ->assertSessionHas('success', 'Paper submitted — you scored 4/5.');

        $this->actingAs($this->student)->get(route('student.exams.review', $exam))
            ->assertOk()
            ->assertSee('4/5')
            ->assertSee('Your answer')
            ->assertSee('Correct answer')
            ->assertSee('Content Calendar) අනිවාර්යයෙන්ම තිබිය යුතුය', false);
    }

    public function test_answers_are_saved_as_they_are_picked(): void
    {
        $exam = $this->publishedPaper();
        $this->actingAs($this->student)->get(route('student.exams.take', $exam))->assertOk();

        $this->actingAs($this->student)->postJson(route('student.exams.save', $exam), ['answers' => $this->rightAnswers($exam, 2) + [999999 => 'stray']])
            ->assertOk();

        $saved = ExamResult::where('exam_id', $exam->id)->where('user_id', $this->student->id)->firstOrFail()->answers;
        $this->assertCount(2, $saved, 'only this paper\'s own questions are kept');

        // Reopening the paper shows them ticked.
        $this->actingAs($this->student)->get(route('student.exams.take', $exam))->assertSee('checked', false);
    }

    /** ⚠️ The deadline is the server's: a late submit counts only what was saved in time. */
    public function test_a_late_submit_is_marked_on_what_was_saved_before_the_deadline(): void
    {
        $exam = $this->publishedPaper(['duration_minutes' => 10]);
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));
        $this->actingAs($this->student)->postJson(route('student.exams.save', $exam), ['answers' => $this->rightAnswers($exam, 2)])->assertOk();

        $this->travel(15)->minutes();

        $this->actingAs($this->student)->postJson(route('student.exams.save', $exam), ['answers' => $this->rightAnswers($exam, 5)])
            ->assertStatus(409);

        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $this->rightAnswers($exam, 5)])
            ->assertSessionHas('error');

        $this->assertSame(2, ExamResult::where('exam_id', $exam->id)->value('marks_obtained'), 'the answers sent after time did not count');
    }

    public function test_coming_back_after_the_time_ran_out_closes_the_paper(): void
    {
        $exam = $this->publishedPaper(['duration_minutes' => 5]);
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));
        $this->actingAs($this->student)->postJson(route('student.exams.save', $exam), ['answers' => $this->rightAnswers($exam, 3)]);

        $this->travel(1)->hours();

        $this->actingAs($this->student)->get(route('student.exams.take', $exam))
            ->assertRedirect(route('student.exams.results'));

        $result = ExamResult::where('exam_id', $exam->id)->firstOrFail();
        $this->assertNotNull($result->submitted_at);
        $this->assertSame(3, $result->marks_obtained);
    }

    public function test_a_submit_just_after_the_deadline_still_counts_in_full(): void
    {
        $exam = $this->publishedPaper(['duration_minutes' => 10]);
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));

        $this->travel(10 * 60 + 30)->seconds(); // the auto-submit arriving a little late

        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $this->rightAnswers($exam, 5)]);

        $this->assertSame(5, ExamResult::where('exam_id', $exam->id)->value('marks_obtained'));
    }

    public function test_the_paper_closing_cuts_an_attempt_short(): void
    {
        $exam = $this->publishedPaper(['duration_minutes' => 60, 'end_at' => now()->addMinutes(5)]);
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));

        $result = ExamResult::where('exam_id', $exam->id)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, \App\Services\ExamGrader::deadline($exam, $result)->timestamp, 2);
    }

    public function test_answers_stay_hidden_when_the_paper_says_so(): void
    {
        $exam = $this->publishedPaper(['show_answers' => false]);
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));
        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $this->rightAnswers($exam)])
            ->assertRedirect(route('student.exams.results'));

        $this->actingAs($this->student)->get(route('student.exams.review', $exam))->assertForbidden();
        $this->actingAs($this->student)->get(route('student.exams.results'))->assertDontSee('View answers');
    }

    public function test_nobody_reviews_a_paper_they_have_not_handed_in(): void
    {
        $exam = $this->publishedPaper();

        $this->actingAs($this->student)->get(route('student.exams.review', $exam))->assertNotFound();
    }

    public function test_a_consultant_who_bought_the_course_can_sit_it(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->enrol($consultant);
        $exam = $this->publishedPaper();

        $this->assertContains('Exams', array_column(Navigation::for($consultant), 'label'));
        $this->actingAs($consultant)->get(route('student.exams.take', $exam))->assertOk();
    }

    // ----- marks for lecturers and admin ----------------------------------------------

    public function test_the_lecturer_and_admin_see_marks_and_each_answer_sheet(): void
    {
        $exam = $this->publishedPaper();
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));
        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $this->rightAnswers($exam, 3)]);
        $result = ExamResult::where('exam_id', $exam->id)->firstOrFail();

        foreach ([$this->lecturer, $this->admin] as $viewer) {
            $this->actingAs($viewer)->get(route('manage.exams.results', $exam))
                ->assertOk()->assertSee('Sample Student')->assertSee('3/5')->assertSee('View answers');

            $this->actingAs($viewer)->get(route('manage.results.sheet', $result))
                ->assertOk()->assertSee('Sample Student')->assertSee('Your answer')->assertSee('Not answered');
        }

        $csv = $this->actingAs($this->admin)->get(route('manage.exams.results', [$exam, 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Sample Student', $csv);
        $this->assertStringContainsString(',3,5,60.00,', $csv);
    }

    public function test_a_paper_still_being_sat_shows_no_marks_yet(): void
    {
        $exam = $this->publishedPaper();
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));

        $this->actingAs($this->lecturer)->get(route('manage.exams.results', $exam))
            ->assertOk()
            ->assertSee('Sitting it now')
            ->assertDontSee('Failed');
    }

    public function test_another_lecturer_cannot_open_an_answer_sheet(): void
    {
        $exam = $this->publishedPaper();
        $this->actingAs($this->student)->get(route('student.exams.take', $exam));
        $other = User::factory()->create();
        $other->syncRoles(['lecturer']);

        $this->actingAs($other)->get(route('manage.results.sheet', ExamResult::firstOrFail()))->assertForbidden();
    }

    // ----- no hints (2026-10-18: "Hint eka ain krann") --------------------------------

    public function test_students_never_see_a_hint_and_the_form_no_longer_asks_for_one(): void
    {
        $exam = $this->exam();
        $this->importPaper($exam, base_path('tests/Fixtures/exam-papers/digital-marketing-lesson01.html'))->assertSessionHasNoErrors();
        $exam->update(['is_published' => true]);
        $hint = 'ධීවරයින් දෙදෙනාගේ කතාවේ';

        $this->actingAs($this->student)->get(route('student.exams.take', $exam))
            ->assertOk()->assertDontSee($hint)->assertDontSee('Hint');

        $answers = [];
        foreach ($exam->questions as $q) {
            $answers[$q->id] = $q->correct_answer;
        }
        $this->actingAs($this->student)->post(route('student.exams.submit', $exam), ['answers' => $answers]);

        $this->actingAs($this->student)->get(route('student.exams.review', $exam))
            ->assertOk()->assertDontSee($hint)->assertDontSee('Hint');

        $this->actingAs($this->admin)->get(route('manage.exams.edit', $exam))
            ->assertOk()->assertDontSee($hint)->assertDontSee('name="hint"', false);
    }

    /** A hint already stored is kept when the question is edited — just never shown. */
    public function test_editing_a_question_keeps_its_stored_hint(): void
    {
        $exam = $this->exam();
        $this->importPaper($exam, base_path('tests/Fixtures/exam-papers/digital-marketing-lesson01.html'));
        $q = $exam->questions()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($this->admin)->patch(route('manage.questions.update', $q), [
            'question' => 'Edited text', 'type' => 'mcq', 'marks' => 1,
            'options' => $q->options, 'correct' => $q->correctIndex(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Edited text', $q->fresh()->question);
        $this->assertNotNull($q->fresh()->hint);
    }

    // ----- times are Sri Lanka time (2026-10-18) -------------------------------------

    /**
     * ⚠️ THE OWNER'S REPORT: a paper set to open at 20:08 still read "Not open
     * yet" at 20:09. The app runs on UTC and "20:08" was stored as 20:08 UTC —
     * 01:38 the next morning in Colombo.
     */
    public function test_a_paper_opens_and_closes_at_the_sri_lanka_time_typed(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-18 13:00:00', 'Asia/Colombo'));

        $exam = $this->publishedPaper();

        $this->actingAs($this->admin)->patch(route('manage.exams.update', $exam), [
            'course_id' => $this->course->id, 'title' => $exam->title, 'type' => 'quiz',
            'total_marks' => 5, 'pass_marks' => 2, 'duration_minutes' => 60,
            'start_at' => '2026-10-18T20:08', 'end_at' => '2026-10-18T21:08', 'show_answers' => 1,
        ])->assertSessionHasNoErrors();

        // Stored exactly as typed, and shown back exactly as typed.
        $this->assertSame('2026-10-18 20:08:00', \Illuminate\Support\Facades\DB::table('exams')->where('id', $exam->id)->value('start_at'));
        $this->actingAs($this->admin)->get(route('manage.exams.edit', $exam))->assertSee('value="2026-10-18T20:08"', false);

        $at = fn (string $colombo) => $this->travelTo(\Illuminate\Support\Carbon::parse("2026-10-18 {$colombo}", 'Asia/Colombo'));

        $at('20:07:00');
        $this->actingAs($this->student)->get(route('student.exams.index'))->assertSee('Not open yet');
        $this->actingAs($this->student)->get(route('student.exams.take', $exam))->assertForbidden();

        $at('20:09:40'); // the owner's screenshot
        $this->actingAs($this->student)->get(route('student.exams.index'))
            ->assertDontSee('Not open yet')
            ->assertSee('Start Exam')
            ->assertSee('opens Oct 18, 20:08');
        $this->actingAs($this->student)->get(route('student.exams.take', $exam))->assertOk();

        $at('21:10:00');
        $this->actingAs($this->student)->get(route('student.exams.index'))->assertDontSee('Start Exam');
    }

    /** Papers saved before the fix were typed in Sri Lanka time too — they now read right. */
    public function test_a_time_saved_before_the_fix_reads_as_sri_lanka_time(): void
    {
        $exam = $this->exam();
        \Illuminate\Support\Facades\DB::table('exams')->where('id', $exam->id)->update(['start_at' => '2026-10-18 20:08:00']);

        $this->assertSame('2026-10-18 14:38:00', $exam->fresh()->start_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Oct 18, 20:08', $exam->fresh()->start_at->format('M d, H:i'));
    }

    // ----- the live site has no CLI ---------------------------------------------------

    public function test_the_new_columns_are_added_by_the_site_itself(): void
    {
        $this->assertTrue(Schema::hasColumn('exam_questions', 'hint'));
        $this->assertTrue(Schema::hasColumn('exam_questions', 'option_explanations'));
        $this->assertTrue(Schema::hasColumn('exams', 'show_answers'));
        $this->assertSame([], ExamSchemaRepair::ensure(), 'a second run changes nothing');
    }
}
