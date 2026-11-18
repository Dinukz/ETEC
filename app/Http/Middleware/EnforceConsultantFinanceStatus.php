<?php

namespace App\Http\Middleware;

use App\Services\ConsultantFinanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks a SUSPENDED consultant out of the site. RESTRICTED consultants are
 * not touched here at all — restriction only holds their income (see
 * ConsultantFinanceService), it never blocks a route.
 *
 * ----- Why GLOBAL, not scoped to the consultant/ route group --------------
 * A consultant is reachable through five separate route groups (their own
 * prefix, reports.*, bookings.*, student.*, and the bare `auth` group that
 * holds checkout.show/checkout.store with NO role middleware at all).
 * checkout.store creates a real Enrollment + Payment — a group-scoped gate
 * would leave a suspended consultant able to keep enrolling students through
 * it. Only a globally-appended middleware can see every one of these.
 *
 * ----- Why this is also where the lazy evaluation lives --------------------
 * This host has no working cron (see routes/console.php), so nothing else
 * ever re-checks a consultant's standing. Running on every request is what
 * "lazy" means here — see ConsultantFinanceService::statusFor() for how that
 * is kept cheap (a session + cached-column-presence short-circuit, not a
 * query on every request in the common case).
 */
class EnforceConsultantFinanceStatus
{
    /** Route names a SUSPENDED consultant may still reach. */
    protected const ALLOWED = [
        // MUST be first conceptually — this is the redirect target. Omitting
        // it would be an infinite redirect loop.
        'consultant.suspended',

        // The consultant's own finance report — they must be able to see WHY
        // they were suspended, not just that they were.
        'reports.finance',

        // The ForcePasswordChange safe island (app/Http/Middleware/ForcePasswordChange.php)
        // — kept identical on purpose so the two middlewares never fight.
        // ForcePasswordChange redirects to profile.edit, which must stay
        // reachable here too or a suspended-and-must-change-password
        // consultant would bounce between the two redirects forever.
        'profile.edit', 'profile.update', 'profile.avatar', 'profile.password.email',
        'password.reset', 'password.update', 'logout',

        // A super admin logged in as a suspended consultant must always be able
        // to get back to their own account (ImpersonationController, 2026-09-14).
        'impersonation.leave',

        // Must be able to read the suspension notice — the layout also runs
        // unreadNotifications() on every dashboard page.
        'notifications.index', 'notifications.read', 'notifications.readAll',
        'verification.notice', 'verification.verify', 'verification.send',

        // Avatars render in the layout chrome on every page, including the
        // suspended page itself.
        'media.show',

        // Public marketing site — nothing consultant-specific happens here,
        // and anonymous visitors can already see all of it.
        'home', 'about', 'faq', 'consultant-program',
        'courses.index', 'courses.show', 'blog.index', 'blog.show',
        'contact', 'contact.store', 'certificates.verify',

        // Product decision: a suspended CONSULTANT still keeps their own LMS
        // access as a learner (the route group's own comment says exactly
        // this — "consultants keep LMS access for their own enrollments").
        // These are the person's own progress/submissions/exam attempts, not
        // consultant actions. Drop this one line for a literal total lock.
        'student.*',
    ];

    public function __construct(protected ConsultantFinanceService $finance)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guests and every non-consultant role are untouched. Zero cost.
        if (! $user || ! $user->hasRole('consultant')) {
            return $next($request);
        }

        try {
            $status = $this->finance->statusFor($user, $request->session());
        } catch (\Throwable $e) {
            // Compliance bookkeeping must never take the site down — the same
            // rule AppServiceProvider::applyMailSettingsFromDatabase() follows
            // for its own per-request database read.
            report($e);

            return $next($request);
        }

        if ($status !== 'suspended' || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        // bookings.availability and the profile fetch()-ed endpoints expect
        // JSON — a 302 to an HTML page is swallowed silently by that JS.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your consultant account is suspended. Contact the administrator.'], 403);
        }

        return redirect()->route('consultant.suspended');
    }
}
