<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\Course;
use App\Models\User;
use App\Services\CourseSchemaRepair;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    use ManagesCourses;

    public function index(Request $request)
    {
        $courses = $this->manageableCourses()
            ->withCount(['enrollments', 'lessons'])
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('manage.courses.index', compact('courses'));
    }

    public function create()
    {
        // The columns behind a course's own half payment / direct service
        // income (2026-10-22) — added by the course screens, no CLI on live.
        CourseSchemaRepair::heal();

        $lecturers = $this->canManageAll() ? User::role('lecturer')->orderBy('name')->get(['id', 'name']) : collect();
        $canSetPaymentPlan = $this->canManageAll();

        return view('manage.courses.create', compact('lecturers', 'canSetPaymentPlan'));
    }

    public function store(Request $request)
    {
        CourseSchemaRepair::heal();

        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['lecturer_id'] = $this->canManageAll() && $request->filled('lecturer_id')
            ? $request->integer('lecturer_id')
            : auth()->id();

        // A course with no default_language/class_type/course_type silently
        // auto-fills nothing on the Add Student form. Fall back to the common
        // baseline so every course works out of the box; still overridable here.
        $data['default_language'] ??= 'Sinhala';
        $data['default_class_type'] ??= 'Online';
        $data['default_course_type'] ??= 'Group';

        // The Level <select> defaults to a blank "All levels" placeholder
        // option (_form.blade.php) — semantically odd for a single course's
        // own level, and the reason this breaks: ConvertEmptyStringsToNull
        // turns that blank submission into an explicit null, 'level' being
        // 'nullable' lets it through validation, and MySQL rejects an
        // EXPLICIT null against a NOT NULL column even though it has a
        // DEFAULT — a column default only ever applies when the column is
        // omitted from the INSERT entirely, never when null is passed for it.
        // Reported 2026-08-19: "Column 'level' cannot be null" on every course
        // creation. Falls back the same way the three defaults above do.
        $data['level'] ??= 'beginner';

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('courses', 'public');
        }

        if (($data['status'] ?? 'draft') === 'published') {
            $data['published_at'] = now();
        }

        $course = Course::create($data);

        return redirect()->route('manage.courses.show', $course)->with('success', "Course \"{$course->title}\" created. Now add modules and lessons.");
    }

    /** Curriculum builder: modules + lessons for one course. */
    public function show(Course $course)
    {
        $this->ensureCanManage($course);
        $course->load(['modules' => fn ($q) => $q->orderBy('sort_order'), 'modules.lessons' => fn ($q) => $q->orderBy('sort_order'), 'lessons' => fn ($q) => $q->orderBy('sort_order')]);
        $unassignedLessons = $course->lessons->whereNull('module_id');

        return view('manage.courses.show', compact('course', 'unassignedLessons'));
    }

    public function edit(Course $course)
    {
        $this->ensureCanManage($course);
        CourseSchemaRepair::heal();

        $lecturers = $this->canManageAll() ? User::role('lecturer')->orderBy('name')->get(['id', 'name']) : collect();
        $canSetPaymentPlan = $this->canManageAll();

        return view('manage.courses.edit', compact('course', 'lecturers', 'canSetPaymentPlan'));
    }

    public function update(Request $request, Course $course)
    {
        $this->ensureCanManage($course);
        CourseSchemaRepair::heal();

        $data = $this->validated($request);

        // Same gap as store() — an admin can reset the Level <select> back to
        // its blank "All levels" placeholder while editing, which submits as
        // an explicit null and hits the NOT NULL column exactly the same way.
        $data['level'] ??= 'beginner';

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('courses', 'public');
        }

        if ($this->canManageAll() && $request->filled('lecturer_id')) {
            $data['lecturer_id'] = $request->integer('lecturer_id');
        }

        if ($data['status'] === 'published' && ! $course->published_at) {
            $data['published_at'] = now();
        }

        $course->update($data);

        return redirect()->route('manage.courses.index')->with('success', "Course \"{$course->title}\" updated.");
    }

    public function destroy(Course $course)
    {
        $this->ensureCanManage($course);
        $course->delete(); // soft delete — enrollments/history stay intact

        return redirect()->route('manage.courses.index')->with('success', "Course \"{$course->title}\" deleted.");
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', 'in:beginner,intermediate,advanced'],
            'duration_text' => ['nullable', 'string', 'max:80'],
            'default_language' => ['nullable', 'in:Sinhala,English,Tamil'],
            'default_class_type' => ['nullable', 'in:Physical,Online,Hybrid'],
            'default_course_type' => ['nullable', 'in:Individual,Group'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'promo_video_url' => ['nullable', 'url', 'max:255'],
            'status' => ['required', 'in:draft,published,archived'],
            'is_featured' => ['nullable', 'boolean'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
        ]);

        return $data + $this->paymentPlan($request, $data);
    }

    /**
     * ⚠️ A COURSE'S OWN HALF PAYMENT AND DIRECT SERVICE INCOME (2026-10-22).
     *
     * Owner: the new "MASTER IN ELLIOTT WAVE" course is 39,000 with a 20,000
     * first instalment and pays 5,000 direct, where every other course uses the
     * standard 18,000 / 4,000. Blank = the standard. See App\Support\CourseMoney.
     *
     * ADMIN / SUPER ADMIN ONLY — the direct is money paid to consultants, so a
     * lecturer's form neither shows nor saves it.
     *
     * Only a field the form actually sent is saved: a page opened before this
     * upload has no such fields, and saving it must not wipe amounts already set.
     *
     * @param  array<string, mixed>  $data  the course fields already validated
     * @return array{half_payment_amount?: float|null, direct_commission?: float|null}
     */
    protected function paymentPlan(Request $request, array $data): array
    {
        if (! $this->canManageAll()) {
            return [];
        }

        // What the student is actually billed — the discount price when there is one.
        $billed = (float) ($data['discount_price'] ?? $data['price']);

        $plan = $request->validate([
            // The first instalment must leave a balance to pay.
            'half_payment_amount' => ['nullable', 'numeric', 'min:1', 'lt:'.$billed],
            // Never 0: a 0 reads as "the bonus is missing" on every check.
            'direct_commission' => ['nullable', 'numeric', 'min:1', 'max:'.(float) $data['price']],
        ], [
            'half_payment_amount.lt' => 'The half payment must be less than the course price (LKR '.number_format($billed).'), so a balance is left to pay.',
            'direct_commission.max' => 'The direct service income cannot be more than the course price.',
        ]);

        $out = [];

        foreach (['half_payment_amount', 'direct_commission'] as $key) {
            if ($request->exists($key)) {
                $out[$key] = isset($plan[$key]) ? round((float) $plan[$key], 2) : null;
            }
        }

        // The columns could not be added (heal() above reports why): saving
        // blank amounts is harmless to skip, but a real amount must never be
        // dropped in silence — the course would quietly pay the standard.
        if ($out !== [] && ! CourseSchemaRepair::ready()) {
            if (array_filter($out, fn ($v) => $v !== null) !== []) {
                throw ValidationException::withMessages([
                    'direct_commission' => 'The half payment / direct service income could not be saved: the database could not be updated. Nothing was saved — please tell the developer.',
                ]);
            }

            return [];
        }

        return $out;
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        for ($i = 2; Course::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
