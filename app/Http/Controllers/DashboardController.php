<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Payment;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $enrollments = Enrollment::query()
            ->where('user_id', $user->id)
            ->with(['course.lecturer'])
            ->whereIn('status', ['active', 'completed'])
            ->latest('enrolled_at')
            ->get();

        $stats = [
            'active' => $enrollments->where('status', 'active')->count(),
            'completed' => $enrollments->where('status', 'completed')->count(),
            'certificates' => Certificate::query()->where('user_id', $user->id)->where('status', 'issued')->count(),
            'avg_progress' => (int) round($enrollments->avg('progress_percentage') ?? 0),
        ];

        $continueLearning = $enrollments
            ->where('status', 'active')
            ->sortByDesc('updated_at')
            ->take(3);

        return view('dashboard.index', compact('user', 'enrollments', 'stats', 'continueLearning'));
    }

    /**
     * "My Courses" — everything this person has registered for, including what
     * they are still waiting to be verified.
     *
     * ⚠️ 'pending' IS LISTED HERE ON PURPOSE, AND ONLY HERE (2026-09-10).
     *
     * Reported: "thaman register una cource eka penwan na" — the course I
     * registered for does not show. It was filtered to
     * ['active','completed'], and a consultant-registered student's enrollment
     * is created 'pending' (EnrollmentService::enroll()) and only becomes
     * 'active' inside PaymentService::verify(). Two manual steps sit in that
     * gap — the consultant confirms a payment plan, then the accountant
     * verifies — so between registering and being verified the course was
     * invisible and the page said "No courses yet".
     *
     * Checkout even redirects here with "your payment is pending verification"
     * (CheckoutController::store()), onto a page that then claimed there was
     * nothing. And partials/enrollment-card.blade.php has carried a
     * "Pending payment verification" branch the whole time — it was dead code,
     * because this query dropped those rows before the card ever saw them.
     *
     * ⚠️ LISTING IS NOT ACCESS. Student\LearnController still requires
     * ['active','completed'] and 403s otherwise ("your payment has not been
     * verified yet"). That gate is what stops an unpaid student watching the
     * course, and it must not be widened to match this. Pinned by
     * PendingEnrollmentIsVisibleTest.
     *
     * The student DASHBOARD (index() above) deliberately does NOT do this: it
     * renders no course list, and its $enrollments only feeds the stat cards —
     * a pending row at 0% would drag "Avg. Progress" down for a course the
     * person has not started.
     */
    public function courses()
    {
        $userId = auth()->id();

        $enrollments = Enrollment::query()
            ->where('user_id', $userId)
            ->with(['course.lecturer'])
            ->whereIn('status', ['pending', 'active', 'completed'])
            ->latest('enrolled_at')
            ->paginate(9);

        // ⚠️ A COURSE YOU PAID FOR MUST SHOW EVEN WITH NO ENROLLMENT ROW.
        //
        // EnrollmentService::enroll() creates the enrollment and the payment
        // together, so in theory this is always empty. In practice the page was
        // still reporting "No courses yet" to somebody who had registered, and
        // an enrollment row can go missing in ways this page cannot see — a
        // cancelled status, a row deleted while cleaning up a duplicate
        // registration, or a payment recorded through a path that never reached
        // enroll().
        //
        // The payment is the harder fact: it is what the person actually did.
        // So anything paid for that has no enrollment behind it is listed
        // anyway, rather than the page quietly claiming they registered for
        // nothing. Same reasoning as the Team Students tab — read from where
        // the fact lives, not from the row that is supposed to mirror it.
        $enrolledCourseIds = Enrollment::where('user_id', $userId)->pluck('course_id');

        $unlinked = Payment::where('user_id', $userId)
            ->whereNotNull('course_id')
            ->whereNotIn('course_id', $enrolledCourseIds)
            ->with(['course.lecturer'])
            ->latest('id')
            ->get()
            ->filter(fn (Payment $payment) => $payment->course !== null)
            ->unique('course_id')
            ->map(function (Payment $payment) {
                // A stand-in for the card to render. Deliberately NOT saved:
                // inventing enrollment rows here would hide the real problem
                // and could collide with unique(user_id, course_id).
                $placeholder = new Enrollment([
                    'course_id' => $payment->course_id,
                    'status' => 'pending',
                    'progress_percentage' => 0,
                ]);

                $placeholder->setRelation('course', $payment->course);

                return $placeholder;
            })
            ->values();

        return view('dashboard.courses', compact('enrollments', 'unlinked'));
    }

    public function certificates()
    {
        $certificates = Certificate::query()
            ->where('user_id', auth()->id())
            ->where('status', 'issued')
            ->with('course')
            ->latest('issued_at')
            ->get();

        return view('dashboard.certificates', compact('certificates'));
    }
}
