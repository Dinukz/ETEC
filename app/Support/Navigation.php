<?php

namespace App\Support;

use App\Models\User;

/**
 * Builds the sidebar navigation for a user based on their primary role.
 * Each item: ['label', 'route' (name or pattern for active state), 'url', 'icon' (svg path d)].
 */
class Navigation
{
    public static function for(User $user): array
    {
        return match ($user->primaryRole()) {
            'super_admin' => self::superAdmin(),
            'admin' => self::admin(),
            'accountant' => self::accountant(),
            'lecturer' => self::lecturer(),
            'group_supporter' => self::groupSupporter(),
            'consultant' => self::consultant($user),
            default => self::student(),
        };
    }

    protected static function item(string $label, string $routeName, string $pattern, string $icon): array
    {
        return [
            'label' => $label,
            'pattern' => $pattern,
            'url' => route($routeName),
            'icon' => $icon,
        ];
    }

    protected static function superAdmin(): array
    {
        return [
            self::item('Dashboard', 'super-admin.dashboard', 'super-admin.dashboard', self::ICON_HOME),
            self::item('User Management', 'users.index', 'users.*', self::ICON_USERS),
            self::item('Courses', 'manage.courses.index', 'manage.courses.*', self::ICON_BOOK),
            self::item('Course Videos', 'manage.course-videos.index', 'manage.course-videos.*', self::ICON_BOOK),
            // 2026-10-18: papers and their marks — the same screens lecturers use.
            self::item('Exams', 'manage.exams.index', 'manage.exams.*', self::ICON_CERT),
            self::item('Payments', 'payments.index', 'payments.*', self::ICON_CASH),
            self::item('Service Income', 'commissions.index', 'commissions.*', self::ICON_CHART),
            self::item('Service Groups', 'groups.index', 'groups.*', self::ICON_GROUP),
            self::item('System Run', 'system-run.index', 'system-run.*', self::ICON_CLOCK),
            self::item('Commission Health', 'commission-health.index', 'commission-health.*', self::ICON_COG),
            self::item('My Bookings', 'bookings.index', 'bookings.index', self::ICON_CALENDAR),
            self::item('All Bookings', 'bookings.all', 'bookings.all', self::ICON_CALENDAR),
            self::item('Manage Halls', 'halls.index', 'halls.index', self::ICON_CALENDAR),
            self::item('Website Content', 'cms.blogs.index', 'cms.*', self::ICON_GLOBE),
            self::item('Admission Report', 'reports.admissions', 'reports.admissions', self::ICON_CHART),
            self::item('Payment Summary Report', 'reports.payments', 'reports.payments', self::ICON_CHART),
            self::item('Finance Report', 'reports.finance', 'reports.finance', self::ICON_CLOCK),
            self::item('Settings', 'settings.index', 'settings.*', self::ICON_COG),
        ];
    }

    protected static function admin(): array
    {
        return [
            self::item('Dashboard', 'admin.dashboard', 'admin.dashboard', self::ICON_HOME),
            self::item('User Management', 'users.index', 'users.*', self::ICON_USERS),
            self::item('Courses', 'manage.courses.index', 'manage.courses.*', self::ICON_BOOK),
            self::item('Course Videos', 'manage.course-videos.index', 'manage.course-videos.*', self::ICON_BOOK),
            // 2026-10-18: papers and their marks — the same screens lecturers use.
            self::item('Exams', 'manage.exams.index', 'manage.exams.*', self::ICON_CERT),
            self::item('Payments', 'payments.index', 'payments.*', self::ICON_CASH),
            self::item('Service Income', 'commissions.index', 'commissions.*', self::ICON_CHART),
            self::item('Service Groups', 'groups.index', 'groups.*', self::ICON_GROUP),
            self::item('System Run', 'system-run.index', 'system-run.*', self::ICON_CLOCK),
            self::item('Commission Health', 'commission-health.index', 'commission-health.*', self::ICON_COG),
            self::item('My Bookings', 'bookings.index', 'bookings.index', self::ICON_CALENDAR),
            self::item('All Bookings', 'bookings.all', 'bookings.all', self::ICON_CALENDAR),
            self::item('Manage Halls', 'halls.index', 'halls.index', self::ICON_CALENDAR),
            self::item('Website Content', 'cms.blogs.index', 'cms.*', self::ICON_GLOBE),
            self::item('Admission Report', 'reports.admissions', 'reports.admissions', self::ICON_CHART),
            self::item('Payment Summary Report', 'reports.payments', 'reports.payments', self::ICON_CHART),
            self::item('Finance Report', 'reports.finance', 'reports.finance', self::ICON_CLOCK),
            self::item('Settings', 'settings.index', 'settings.*', self::ICON_COG),
        ];
    }

    protected static function accountant(): array
    {
        return [
            self::item('Dashboard', 'accountant.dashboard', 'accountant.dashboard', self::ICON_HOME),
            self::item('Payments', 'payments.index', 'payments.*', self::ICON_CASH),
            self::item('Service Income', 'commissions.index', 'commissions.index', self::ICON_CHART),
            self::item('Service Income Summary', 'commissions.history', 'commissions.history', self::ICON_CHART),
            self::item('Company Revenue', 'commissions.revenue', 'commissions.revenue', self::ICON_CASH),
            self::item('System Run', 'system-run.index', 'system-run.*', self::ICON_CLOCK),
            self::item('Admission Report', 'reports.admissions', 'reports.admissions', self::ICON_CHART),
            self::item('Payment Summary Report', 'reports.payments', 'reports.payments', self::ICON_CHART),
            self::item('Finance Report', 'reports.finance', 'reports.finance', self::ICON_CLOCK),
            self::item('My Bookings', 'bookings.index', 'bookings.index', self::ICON_CALENDAR),
            self::item('All Bookings', 'bookings.all', 'bookings.all', self::ICON_CALENDAR),
        ];
    }

    protected static function lecturer(): array
    {
        return [
            self::item('Dashboard', 'lecturer.dashboard', 'lecturer.dashboard', self::ICON_HOME),
            self::item('My Courses', 'manage.courses.index', 'manage.courses.*', self::ICON_BOOK),
            self::item('Course Videos', 'manage.course-videos.index', 'manage.course-videos.*', self::ICON_BOOK),
            self::item('Assignments', 'manage.assignments.index', 'manage.assignments.*', self::ICON_PENCIL),
            self::item('Exams', 'manage.exams.index', 'manage.exams.*', self::ICON_CERT),
            self::item('Zoom Meetings', 'manage.zoom.index', 'manage.zoom.*', self::ICON_VIDEO),
            // My Bookings / All Bookings removed 2026-10-24 — not for lecturers.
        ];
    }

    /**
     * GROUP SUPPORTER (2026-09-17) — these two pages and nothing else: "Payments
     * tab ekat saha Admission Report ekat withri ynn puluwn". Payments are
     * view-only for them; see User::canActionPayments().
     */
    protected static function groupSupporter(): array
    {
        return [
            self::item('Payments', 'payments.index', 'payments.*', self::ICON_CASH),
            // Admission Report removed 2026-10-05 — Payments only now.
        ];
    }

    protected static function consultant(User $user): array
    {
        // A suspended consultant is locked out of nearly everything by
        // EnforceConsultantFinanceStatus anyway — this is purely cosmetic
        // (the middleware is the real gate), but a menu full of dead links to
        // pages that just redirect back here would be confusing.
        // ⚠️ FOUR PAGES ARE GRANTED PER CONSULTANT (2026-09-22) — Admission
        // Report, Payment Summary Report, My Bookings, All Bookings. Hidden here
        // unless granted; ConsultantPageAccess is the real gate on the routes.
        $may = fn (string $page) => (bool) $user->consultant?->hasPageAccess($page);

        if ($user->consultant?->isFinanceSuspended()) {
            return array_values(array_filter([
                self::item('Account Suspended', 'consultant.suspended', 'consultant.suspended', self::ICON_CLOCK),
                self::item('Finance Report', 'reports.finance', 'reports.finance', self::ICON_CHART),
                self::item('My Courses', 'student.courses', 'student.courses', self::ICON_BOOK),
                $may('my_bookings') ? self::item('My Bookings', 'bookings.index', 'bookings.index', self::ICON_CALENDAR) : null,
            ]));
        }

        return array_values(array_filter([
            self::item('Dashboard', 'consultant.dashboard', 'consultant.dashboard', self::ICON_HOME),
            self::item('Student Registration', 'consultant.students.create', 'consultant.students.create', self::ICON_USERS),
            self::item('Pending Students', 'consultant.students.pending', 'consultant.students.pending', self::ICON_CLOCK),
            self::item('Direct Registrations', 'consultant.students.index', 'consultant.students.index', self::ICON_CHART),
            self::item('Service Groups', 'consultant.groups.index', 'consultant.groups.*', self::ICON_GROUP),
            self::item('Service Income', 'consultant.earnings.index', 'consultant.earnings.*', self::ICON_CHART),
            $may('admissions') ? self::item('Admission Report', 'reports.admissions', 'reports.admissions', self::ICON_CHART) : null,
            $may('payment_summary') ? self::item('Payment Summary Report', 'reports.payments', 'reports.payments', self::ICON_CHART) : null,
            self::item('Finance Report', 'reports.finance', 'reports.finance', self::ICON_CLOCK),
            self::item('My Courses', 'student.courses', 'student.courses', self::ICON_BOOK),
            // A consultant sits the papers of courses they bought, like any student (2026-10-18).
            self::item('Exams', 'student.exams.index', 'student.exams.*', self::ICON_CERT),
            $may('my_bookings') ? self::item('My Bookings', 'bookings.index', 'bookings.index', self::ICON_CALENDAR) : null,
            $may('all_bookings') ? self::item('All Bookings', 'bookings.all', 'bookings.all', self::ICON_CALENDAR) : null,
        ]));
    }

    protected static function student(): array
    {
        return [
            self::item('Dashboard', 'student.dashboard', 'student.dashboard', self::ICON_HOME),
            self::item('My Courses', 'student.courses', 'student.courses', self::ICON_BOOK),
            self::item('Assignments', 'student.assignments.index', 'student.assignments.*', self::ICON_PENCIL),
            self::item('Exams', 'student.exams.index', 'student.exams.index', self::ICON_CERT),
            self::item('Results', 'student.exams.results', 'student.exams.results', self::ICON_CHART),
            self::item('Certificates', 'student.certificates', 'student.certificates*', self::ICON_CERT),
            self::item('Browse Courses', 'courses.index', 'courses.*', self::ICON_SEARCH),
        ];
    }

    // ---- SVG path data (stroke, 24x24) --------------------------------------
    public const ICON_HOME = 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6';
    public const ICON_USERS = 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z';
    public const ICON_BOOK = 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253';
    public const ICON_CERT = 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z';
    public const ICON_SEARCH = 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z';
    public const ICON_CASH = 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z';
    public const ICON_CHART = 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z';
    public const ICON_GROUP = 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z';
    public const ICON_COG = 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z';
    public const ICON_CLOCK = 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z';
    public const ICON_CALENDAR = 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z';
    public const ICON_GLOBE = 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9';
    public const ICON_PENCIL = 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z';
    public const ICON_VIDEO = 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z';
    public const ICON_LINK = 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1';
}
