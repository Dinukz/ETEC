<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Dashboard\AccountantDashboardController;
use App\Http\Controllers\Dashboard\AdminDashboardController;
use App\Http\Controllers\Dashboard\CommissionController;
use App\Http\Controllers\Dashboard\ConsultantDashboardController;
use App\Http\Controllers\Dashboard\GroupController;
use App\Http\Controllers\Dashboard\LecturerDashboardController;
use App\Http\Controllers\Dashboard\PaymentController;
use App\Http\Controllers\Dashboard\SettingController;
use App\Http\Controllers\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Dashboard\UserManagementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/about', [HomeController::class, 'about'])->name('about');
// Every public page, for Google Search Console (2026-09-30). See SitemapController.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');

// Avatars/account-proof — served via PHP instead of the (unreliable on this
// host) public/storage symlink. See MediaController for the allow-list.
Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*')->name('media.show');
Route::get('/services', [HomeController::class, 'services'])->name('services');
Route::get('/careers', [HomeController::class, 'careers'])->name('careers');
Route::get('/consultant-program', [HomeController::class, 'consultantProgram'])->name('consultant-program');
Route::get('/faq', [HomeController::class, 'faq'])->name('faq');

Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{blog}', [BlogController::class, 'show'])->name('blog.show');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('/verify-certificate', [\App\Http\Controllers\CertificateVerifyController::class, 'show'])->name('certificates.verify');

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
});

// Not guest-only: a user may still be logged in when they open the emailed
// reset link (e.g. clicked from the "reset via email" button on their own
// profile page), and should still be able to complete the reset.
Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');

/*
|--------------------------------------------------------------------------
| Authenticated area (shared)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // The way back from a super admin's "Log in as this user". Open to whoever
    // is signed in, because by then that IS the user — leave() checks the
    // session for the super admin it came from and refuses without one.
    Route::post('/impersonation/leave', [\App\Http\Controllers\Dashboard\ImpersonationController::class, 'leave'])->name('impersonation.leave');

    // Email verification
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    // Generic entrypoint — bounces the user to their role dashboard.
    Route::get('/dashboard', fn () => redirect()->route(auth()->user()->dashboardRoute()))->name('dashboard');

    // Profile (available to every authenticated role)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::post('/profile/password/email', [ProfileController::class, 'sendPasswordResetLink'])->name('profile.password.email');

    // In-app notifications (all roles)
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('notifications.read');

    // Self-service checkout (any authenticated user can purchase a course)
    Route::get('/checkout/{course}', [\App\Http\Controllers\CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout/{course}', [\App\Http\Controllers\CheckoutController::class, 'store'])->name('checkout.store');
});

/*
|--------------------------------------------------------------------------
| Super Admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:super_admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    Route::get('/dashboard', SuperAdminDashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| User management — shared by Super Admin + Admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:super_admin|admin'])->group(function () {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    // Super admin only — the group also admits admins. See ImpersonationController.
    Route::post('/users/{user}/login-as', [\App\Http\Controllers\Dashboard\ImpersonationController::class, 'start'])
        ->middleware('role:super_admin')->name('users.login-as');
    Route::patch('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role');
    Route::patch('/users/{user}/password', [UserManagementController::class, 'updatePassword'])->name('users.password');
    Route::patch('/users/{user}/unlock-profile', [UserManagementController::class, 'unlockProfile'])->name('users.unlock-profile');
    // Let ONE consultant see the admissions of the people below them. Off for
    // everyone until an admin turns it on here — see the action's docblock.
    Route::patch('/users/{user}/downline-report', [UserManagementController::class, 'updateDownlineReport'])->name('users.downline-report');
    // Which optional pages a consultant may open (2026-09-22). See Consultant::PAGE_ACCESS.
    Route::patch('/users/{user}/page-access', [UserManagementController::class, 'updatePageAccess'])->name('users.page-access');
    // The courses a lecturer teaches — their exams and assignments (2026-10-25). See LecturerCourses.
    Route::patch('/users/{user}/lecturer-courses', [UserManagementController::class, 'updateLecturerCourses'])->name('users.lecturer-courses');
    // ⚠️ SUPER ADMIN ONLY — enforced inside the methods, because this whole
    // group is role:super_admin|admin and middleware cannot narrow one route.
    // POST, not PATCH: the profile form carries a file (account_proof), and PHP
    // parses a multipart body only on a real POST.
    Route::post('/users/{user}/profile-details', [UserManagementController::class, 'updateProfileDetails'])->name('users.profile-details');
    Route::post('/users/{user}/resend-registration', [UserManagementController::class, 'resendRegistrationEmail'])->name('users.resend-registration');
    // Manually open/shut one of a consultant's Service Group placement
    // accounts, overriding the automatic 12-students unlock rule.
    Route::patch('/consultant-accounts/{consultant}/lock', [UserManagementController::class, 'updateAccountLock'])->name('users.account-lock');
    // Move an account forward along the 1-to-1 → 2-to-2 → 3-to-3 ladder.
    Route::patch('/consultant-accounts/{consultant}/stage', [UserManagementController::class, 'updateAccountStage'])->name('users.account-stage');
    // Review which accounts carry no money, and reclaim the numbers they hold.
    // Both super-admin only; the review screen deletes nothing.
    Route::get("/users/cleanup", [UserManagementController::class, "cleanup"])->name("users.cleanup");
    Route::post("/users/recompute-numbering", [UserManagementController::class, "recomputeNumbering"])->name("users.recompute-numbering");
    // Registered BEFORE the {user} route so "bulk" is never read as an id.
    Route::delete('/users/bulk', [UserManagementController::class, 'bulkDestroy'])->name('users.bulk-destroy');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    // System & commission settings — no hardcoded values, all editable here.
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::patch('/settings/general', [SettingController::class, 'updateGeneral'])->name('settings.general');
    Route::patch('/settings/commission', [SettingController::class, 'updateCommission'])->name('settings.commission');
    Route::patch('/settings/switches', [SettingController::class, 'updateSwitches'])->name('settings.switches');
    Route::patch('/settings/mail', [SettingController::class, 'updateMail'])->name('settings.mail');
    Route::post('/settings/mail/test', [SettingController::class, 'testMail'])->name('settings.mail.test');

    // Why is a commission missing? Diagnose + repair from the browser (no CLI here).
    Route::get('/commission-health', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'index'])->name('commission-health.index');
    Route::post('/commission-health/repair', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'repair'])->name('commission-health.repair');
    Route::post('/commission-health/reconcile-migrations', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'reconcileMigrations'])->name('commission-health.reconcile');
    // Approve income recorded before auto-approval existed (no CLI on prod).
    Route::post('/commission-health/approve-historic', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'approveHistoric'])->name('commission-health.approve-historic');
    // Move upline commissions that were routed onto the wrong Service Group.
    Route::post('/commission-health/repair-legs', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'repairLegs'])->name('commission-health.repair-legs');
    // Where each bought account sits, and a way to correct one whose climb
    // has no parent to walk to (the SCN-00122 report). Super admin only.
    Route::get("/commission-health/placements", [\App\Http\Controllers\Dashboard\CommissionHealthController::class, "placements"])->name("commission-health.placements");
    Route::post("/commission-health/placements", [\App\Http\Controllers\Dashboard\CommissionHealthController::class, "repointPlacement"])->name("commission-health.repoint");
    // READ-ONLY: check every recorded 2,500 / 4,000 / upline 2,500 against the
    // rules in one pass, plus every account stage against the pairings it has
    // actually completed. Writes nothing. See CommissionRuleAudit.
    // A branch whose upline was cut by a deletion, and where it belongs.
    Route::get('/commission-health/broken-uplines', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'brokenUplines'])->name('commission-health.broken-uplines');
    Route::post('/commission-health/broken-uplines', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'reconnectUpline'])->name('commission-health.reconnect-upline');
    // Hand entries that stopped short of a main account (2026-09-27). Preview,
    // then add exactly what the preview lists. Super admin only.
    Route::get('/commission-health/manual-mains', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'manualMains'])->name('commission-health.manual-mains');
    Route::post('/commission-health/manual-mains', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'repairManualMains'])->name('commission-health.repair-manual-mains');
    // Courses bought on a free card that never opened their SCN accounts (2026-09-14).
    Route::post('/commission-health/open-account-sets', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'openMissingAccountSets'])->name('commission-health.open-account-sets');
    // Income hidden by the old weekly-quota hold, before 2026-09-15.
    Route::post('/commission-health/release-held', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'releaseHeldIncome'])->name('commission-health.release-held');

    Route::get('/commission-health/rule-audit', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'ruleAudit'])->name('commission-health.rule-audit');
    // Put a stage back to what the account really paired for (super admin only).
    Route::post('/commission-health/repair-stages', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'repairStages'])->name('commission-health.repair-stages');
    // READ-ONLY: could every consultant's SCN number match their SRN number?
    // Counts the real collisions on live data — writes nothing. See ScnNumberAudit.
    Route::get('/commission-health/scn-numbers', [\App\Http\Controllers\Dashboard\CommissionHealthController::class, 'scnNumbers'])->name('commission-health.scn-numbers');

    // Group management (admin + super admin)
    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups/accounts/{consultant}', [GroupController::class, 'addAccount'])->name('groups.add-account');
    // Hand-enter a commission the engine missed. Super-admin only, checked in
    // the controller — this creates money. See ManualCommissionEntry.
    Route::post('/groups/manual-commission', [GroupController::class, 'addManualCommission'])->name('groups.manual-commission');
    Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');

    // Hall management — only admin/super admin may add or edit halls.
    Route::get('/halls', [\App\Http\Controllers\Dashboard\HallController::class, 'index'])->name('halls.index');
    Route::post('/halls', [\App\Http\Controllers\Dashboard\HallController::class, 'store'])->name('halls.store');
    Route::patch('/halls/{hall}', [\App\Http\Controllers\Dashboard\HallController::class, 'update'])->name('halls.update');
    Route::patch('/halls/{hall}/toggle', [\App\Http\Controllers\Dashboard\HallController::class, 'toggle'])->name('halls.toggle');

    // Website CMS — blog, testimonials, FAQs, pages, contact messages
    Route::prefix('cms')->name('cms.')->group(function () {
        Route::resource('blogs', \App\Http\Controllers\Cms\BlogController::class)->except(['show']);
        Route::resource('testimonials', \App\Http\Controllers\Cms\TestimonialController::class)->except(['show']);
        Route::resource('faqs', \App\Http\Controllers\Cms\FaqController::class)->except(['show']);
        Route::resource('pages', \App\Http\Controllers\Cms\PageController::class)->except(['show']);
        Route::get('/messages', [\App\Http\Controllers\Cms\ContactMessageController::class, 'index'])->name('messages.index');
        Route::get('/messages/{message}', [\App\Http\Controllers\Cms\ContactMessageController::class, 'show'])->name('messages.show');
        Route::patch('/messages/{message}/status', [\App\Http\Controllers\Cms\ContactMessageController::class, 'updateStatus'])->name('messages.status');
        Route::delete('/messages/{message}', [\App\Http\Controllers\Cms\ContactMessageController::class, 'destroy'])->name('messages.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Finance — payments & commissions (accountant + admin + super admin)
|--------------------------------------------------------------------------
*/
// Looking at payments — a group supporter may do this, and ONLY this (2026-09-17).
Route::middleware(['auth', 'role:super_admin|admin|accountant|group_supporter'])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
});

Route::middleware(['auth', 'role:super_admin|admin|accountant'])->group(function () {
    Route::patch('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::patch('/payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');

    Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::get('/commissions/history', [CommissionController::class, 'history'])->name('commissions.history');
    Route::get('/commissions/revenue', [CommissionController::class, 'revenue'])->name('commissions.revenue');
    Route::patch('/commissions/approve-all', [CommissionController::class, 'approveAll'])->name('commissions.approve-all');
    Route::patch('/commissions/consultant/{consultant}/approve-all', [CommissionController::class, 'approveAllForConsultant'])->name('commissions.approve-consultant');
    Route::patch('/commissions/consultant/{consultant}/approve-stage', [CommissionController::class, 'approveStage'])->name('commissions.approve-stage');
    Route::patch('/commissions/{commission}/approve', [CommissionController::class, 'approve'])->name('commissions.approve');
    Route::patch('/commissions/{commission}/paid', [CommissionController::class, 'markPaid'])->name('commissions.paid');

    // The daily System Run — network-wide flush of yesterday's activations.
    // ⚠️ THE RULES THE RUN OBEYS, EDITABLE (super admin only). Data, not code —
    // see SystemRunRulesController for why. Registered BEFORE the wildcard
    // routes below so "rules" is never read as a {run} id.
    Route::get('/system-run/rules', [\App\Http\Controllers\Dashboard\SystemRunRulesController::class, 'index'])->name('system-run.rules');
    Route::post('/system-run/rules', [\App\Http\Controllers\Dashboard\SystemRunRulesController::class, 'update'])->name('system-run.rules.update');

    Route::get('/system-run', [\App\Http\Controllers\Dashboard\SystemRunController::class, 'index'])->name('system-run.index');
    Route::post('/system-run/announce', [\App\Http\Controllers\Dashboard\SystemRunController::class, 'announce'])->name('system-run.announce');
    // Undo one account from a past run (super admin only; checked in the controller).
    Route::post('/system-run/{run}/reverse', [\App\Http\Controllers\Dashboard\SystemRunController::class, 'reverseAccount'])->name('system-run.reverse');
    Route::post('/system-run/run', [\App\Http\Controllers\Dashboard\SystemRunController::class, 'run'])->name('system-run.run');
    // Drains the email outbox by hand. The scheduler does this every minute
    // IF cron is running `schedule:run`; this button is what makes the queue
    // safe when it is not.
    Route::post('/system-run/notices/send', [\App\Http\Controllers\Dashboard\SystemRunController::class, 'sendPendingNotices'])->name('system-run.notices.send');
});

// Reports — accountant/admin/super admin see every consultant; a consultant sees only their own row.
// ⚠️ NOT a group supporter's any more (2026-10-05): "Group supporter access eken
// Admission Report eka ain krala denn". They had it from 2026-09-17.
Route::get('/reports/admissions', [\App\Http\Controllers\Dashboard\ReportController::class, 'admissions'])
    // consultant.page: a consultant needs the Admission Report grant (2026-09-22).
    ->middleware(['auth', 'role:super_admin|admin|accountant|consultant', 'consultant.page:admissions'])
    ->name('reports.admissions');

Route::middleware(['auth', 'role:super_admin|admin|accountant|consultant'])->group(function () {
    Route::get('/reports/payments', [\App\Http\Controllers\Dashboard\ReportController::class, 'payments'])->middleware('consultant.page:payment_summary')->name('reports.payments');
    Route::get('/reports/finance', [\App\Http\Controllers\Dashboard\FinanceReportController::class, 'index'])->name('reports.finance');
});

// Admin-only finance actions — separate from the report route above (which a
// consultant may also GET) because these mutate every consultant's state.
Route::middleware(['auth', 'role:super_admin|admin'])->group(function () {
    Route::post('/finance/evaluate', [\App\Http\Controllers\Dashboard\FinanceReportController::class, 'evaluateAll'])->name('finance.evaluate');
    Route::post('/finance/consultants/{consultant}/reactivate', [\App\Http\Controllers\Dashboard\FinanceReportController::class, 'reactivate'])->name('finance.reactivate');
});

/*
|--------------------------------------------------------------------------
| Course management (LMS authoring) — lecturers own their courses,
| admins & super admins manage all
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:super_admin|admin|lecturer'])->prefix('manage')->name('manage.')->group(function () {
    // Course videos: attach files already on the server (placed by FTP into
    // resources/Videos) to lessons, and handle "let me watch it again" requests.
    // Since 2026-10-10 a video can also be UPLOADED here — in pieces, then
    // web-optimised without re-encoding (CourseVideoUploadController).
    Route::get('/course-videos', [\App\Http\Controllers\Manage\CourseVideoController::class, 'index'])->name('course-videos.index');
    Route::post('/course-videos/upload', [\App\Http\Controllers\Manage\CourseVideoUploadController::class, 'start'])->name('course-videos.upload.start');
    Route::post('/course-videos/upload/{upload}/chunk', [\App\Http\Controllers\Manage\CourseVideoUploadController::class, 'chunk'])->where('upload', '[a-f0-9]{32}')->name('course-videos.upload.chunk');
    Route::post('/course-videos/upload/{upload}/finish', [\App\Http\Controllers\Manage\CourseVideoUploadController::class, 'finish'])->where('upload', '[a-f0-9]{32}')->name('course-videos.upload.finish');
    Route::post('/course-videos/optimise', [\App\Http\Controllers\Manage\CourseVideoUploadController::class, 'optimise'])->name('course-videos.optimise');
    Route::post('/course-videos/jobs/{job}/step', [\App\Http\Controllers\Manage\CourseVideoUploadController::class, 'step'])->where('job', '[a-f0-9]{32}')->name('course-videos.job.step');
    Route::post('/course-videos/requests/bulk', [\App\Http\Controllers\Manage\CourseVideoController::class, 'bulkRequests'])->name('course-videos.requests.bulk');
    Route::post('/course-videos/attach', [\App\Http\Controllers\Manage\CourseVideoController::class, 'attach'])->name('course-videos.attach');
    Route::post('/course-videos/attach-many', [\App\Http\Controllers\Manage\CourseVideoController::class, 'attachMany'])->name('course-videos.attach-many');
    // Preview a library file in the admin screen. A SECOND door to the same
    // files as the student route, gated by this group's manage role instead of
    // an enrollment — and it consumes no student viewing window.
    Route::get('/course-videos/preview', [\App\Http\Controllers\Manage\CourseVideoController::class, 'preview'])->name('course-videos.preview');
    Route::post('/course-videos/reorder', [\App\Http\Controllers\Manage\CourseVideoController::class, 'reorder'])->name('course-videos.reorder');
    // Remove it for students for real: the lesson AND the file (destroy), or
    // just one lesson off the order list (remove-lesson). Detach below only
    // unhooks the file and leaves the lesson on the student's sidebar.
    Route::post('/course-videos/destroy', [\App\Http\Controllers\Manage\CourseVideoController::class, 'destroy'])->name('course-videos.destroy');
    Route::post('/course-videos/remove-lesson', [\App\Http\Controllers\Manage\CourseVideoController::class, 'removeLesson'])->name('course-videos.remove-lesson');

    Route::post('/course-videos/detach', [\App\Http\Controllers\Manage\CourseVideoController::class, 'detach'])->name('course-videos.detach');
    Route::post('/course-videos/requests/{viewRequest}/approve', [\App\Http\Controllers\Manage\CourseVideoController::class, 'approveRequest'])->name('course-videos.approve');
    Route::post('/course-videos/requests/{viewRequest}/decline', [\App\Http\Controllers\Manage\CourseVideoController::class, 'declineRequest'])->name('course-videos.decline');

    Route::get('/courses', [\App\Http\Controllers\Manage\CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [\App\Http\Controllers\Manage\CourseController::class, 'create'])->name('courses.create');
    Route::post('/courses', [\App\Http\Controllers\Manage\CourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}', [\App\Http\Controllers\Manage\CourseController::class, 'show'])->name('courses.show');
    Route::get('/courses/{course}/edit', [\App\Http\Controllers\Manage\CourseController::class, 'edit'])->name('courses.edit');
    Route::patch('/courses/{course}', [\App\Http\Controllers\Manage\CourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [\App\Http\Controllers\Manage\CourseController::class, 'destroy'])->name('courses.destroy');

    Route::post('/courses/{course}/modules', [\App\Http\Controllers\Manage\ModuleController::class, 'store'])->name('modules.store');
    Route::patch('/modules/{module}', [\App\Http\Controllers\Manage\ModuleController::class, 'update'])->name('modules.update');
    Route::delete('/modules/{module}', [\App\Http\Controllers\Manage\ModuleController::class, 'destroy'])->name('modules.destroy');

    Route::get('/courses/{course}/lessons/create', [\App\Http\Controllers\Manage\LessonController::class, 'create'])->name('lessons.create');
    Route::post('/courses/{course}/lessons', [\App\Http\Controllers\Manage\LessonController::class, 'store'])->name('lessons.store');
    Route::get('/lessons/{lesson}/edit', [\App\Http\Controllers\Manage\LessonController::class, 'edit'])->name('lessons.edit');
    Route::patch('/lessons/{lesson}', [\App\Http\Controllers\Manage\LessonController::class, 'update'])->name('lessons.update');
    Route::delete('/lessons/{lesson}', [\App\Http\Controllers\Manage\LessonController::class, 'destroy'])->name('lessons.destroy');
    Route::delete('/materials/{material}', [\App\Http\Controllers\Manage\LessonController::class, 'destroyMaterial'])->name('materials.destroy');

    Route::get('/assignments', [\App\Http\Controllers\Manage\AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/create', [\App\Http\Controllers\Manage\AssignmentController::class, 'create'])->name('assignments.create');
    Route::post('/assignments', [\App\Http\Controllers\Manage\AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/assignments/{assignment}', [\App\Http\Controllers\Manage\AssignmentController::class, 'show'])->name('assignments.show');
    Route::get('/assignments/{assignment}/edit', [\App\Http\Controllers\Manage\AssignmentController::class, 'edit'])->name('assignments.edit');
    Route::patch('/assignments/{assignment}', [\App\Http\Controllers\Manage\AssignmentController::class, 'update'])->name('assignments.update');
    Route::delete('/assignments/{assignment}', [\App\Http\Controllers\Manage\AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::patch('/submissions/{submission}/grade', [\App\Http\Controllers\Manage\AssignmentController::class, 'grade'])->name('submissions.grade');

    Route::get('/exams', [\App\Http\Controllers\Manage\ExamController::class, 'index'])->name('exams.index');
    Route::get('/exams/create', [\App\Http\Controllers\Manage\ExamController::class, 'create'])->name('exams.create');
    Route::post('/exams', [\App\Http\Controllers\Manage\ExamController::class, 'store'])->name('exams.store');
    Route::get('/exams/{exam}/edit', [\App\Http\Controllers\Manage\ExamController::class, 'edit'])->name('exams.edit');
    Route::patch('/exams/{exam}', [\App\Http\Controllers\Manage\ExamController::class, 'update'])->name('exams.update');
    Route::delete('/exams/{exam}', [\App\Http\Controllers\Manage\ExamController::class, 'destroy'])->name('exams.destroy');
    Route::patch('/exams/{exam}/publish', [\App\Http\Controllers\Manage\ExamController::class, 'publish'])->name('exams.publish');
    Route::get('/exams/{exam}/results', [\App\Http\Controllers\Manage\ExamController::class, 'results'])->name('exams.results');
    Route::patch('/exams/{exam}/results/publish', [\App\Http\Controllers\Manage\ExamController::class, 'publishResults'])->name('exams.publishResults');
    Route::patch('/results/{result}/grade', [\App\Http\Controllers\Manage\ExamController::class, 'gradeResult'])->name('results.grade');
    // 2026-10-18: a whole paper at once (the owner's HTML quiz, JSON or typed text), and one student's answer sheet.
    Route::post('/exams/{exam}/import', [\App\Http\Controllers\Manage\ExamController::class, 'import'])->name('exams.import');
    Route::get('/exam-results/{result}', [\App\Http\Controllers\Manage\ExamController::class, 'answerSheet'])->name('results.sheet');
    Route::post('/exams/{exam}/questions', [\App\Http\Controllers\Manage\ExamController::class, 'storeQuestion'])->name('questions.store');
    Route::patch('/questions/{question}', [\App\Http\Controllers\Manage\ExamController::class, 'updateQuestion'])->name('questions.update');
    Route::delete('/questions/{question}', [\App\Http\Controllers\Manage\ExamController::class, 'destroyQuestion'])->name('questions.destroy');

    Route::get('/zoom', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'index'])->name('zoom.index');
    Route::get('/zoom/create', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'create'])->name('zoom.create');
    Route::post('/zoom', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'store'])->name('zoom.store');
    Route::get('/zoom/{meeting}/edit', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'edit'])->name('zoom.edit');
    Route::patch('/zoom/{meeting}', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'update'])->name('zoom.update');
    Route::delete('/zoom/{meeting}', [\App\Http\Controllers\Manage\ZoomMeetingController::class, 'destroy'])->name('zoom.destroy');
});

/*
|--------------------------------------------------------------------------
| Hall bookings — staff-facing roles, NOT lecturers (removed 2026-10-24; see
| HallBookingController::middleware() for a lecturer who holds another role)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:super_admin|admin|accountant|consultant'])->group(function () {
    Route::get('/bookings', [\App\Http\Controllers\HallBookingController::class, 'index'])->middleware('consultant.page:my_bookings')->name('bookings.index');
    Route::get('/bookings/all', [\App\Http\Controllers\HallBookingController::class, 'all'])->middleware('consultant.page:all_bookings')->name('bookings.all');
    Route::get('/bookings/create', [\App\Http\Controllers\HallBookingController::class, 'create'])->middleware('consultant.page:my_bookings')->name('bookings.create');
    Route::get('/bookings/availability', [\App\Http\Controllers\HallBookingController::class, 'availability'])->middleware('consultant.page:my_bookings')->name('bookings.availability');
    Route::post('/bookings', [\App\Http\Controllers\HallBookingController::class, 'store'])->middleware('consultant.page:my_bookings')->name('bookings.store');
    Route::patch('/bookings/{booking}/cancel', [\App\Http\Controllers\HallBookingController::class, 'cancel'])->middleware('consultant.page:my_bookings')->name('bookings.cancel');
});

/*
|--------------------------------------------------------------------------
| Accountant
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:accountant'])->prefix('accountant')->name('accountant.')->group(function () {
    Route::get('/dashboard', AccountantDashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Lecturer
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:lecturer'])->prefix('lecturer')->name('lecturer.')->group(function () {
    Route::get('/dashboard', LecturerDashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Consultant
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:consultant'])->prefix('consultant')->name('consultant.')->group(function () {
    Route::get('/dashboard', ConsultantDashboardController::class)->name('dashboard');

    // My students — add / invite / view referred students
    Route::get('/students', [\App\Http\Controllers\Consultant\StudentController::class, 'index'])->name('students.index');
    Route::get('/students/pending', [\App\Http\Controllers\Consultant\StudentController::class, 'pending'])->name('students.pending');
    Route::get('/students/create', [\App\Http\Controllers\Consultant\StudentController::class, 'create'])->name('students.create');
    Route::post('/students', [\App\Http\Controllers\Consultant\StudentController::class, 'store'])->name('students.store');
    Route::patch('/payments/{payment}/confirm', [\App\Http\Controllers\Consultant\StudentController::class, 'confirmPayment'])->name('payments.confirm');
    Route::post('/payments/{payment}/pay-balance', [\App\Http\Controllers\Consultant\StudentController::class, 'payBalance'])->name('payments.pay-balance');
    // Chasing / cancelling a registration that is still waiting to be paid.
    // Re-submit a REJECTED payment with a corrected slip. Without this a
    // rejection was terminal — the registration dropped off Pending Students
    // and the consultant had no way to attach a replacement.
    Route::post('/payments/{payment}/resubmit', [\App\Http\Controllers\Consultant\StudentController::class, 'resubmitPayment'])->name('payments.resubmit');
    Route::post('/payments/{payment}/resend-email', [\App\Http\Controllers\Consultant\StudentController::class, 'resendEmail'])->name('payments.resend-email');
    Route::delete('/payments/{payment}', [\App\Http\Controllers\Consultant\StudentController::class, 'destroyRegistration'])->name('payments.destroy');

    // My groups + members
    Route::get('/groups', [\App\Http\Controllers\Consultant\GroupController::class, 'index'])->name('groups.index');
    // NOTE: there is deliberately no per-account flush route here any more.
    // Flushing became the accounts unit's once-daily System Run on 2026-08-14
    // (system-run.*), so a consultant can no longer settle their own legs.
    Route::get('/groups/{group}', [\App\Http\Controllers\Consultant\GroupController::class, 'show'])->name('groups.show');

    // The consultant tree below this consultant
    Route::get('/network', [\App\Http\Controllers\Consultant\NetworkController::class, 'index'])->name('network.index');

    // Earnings / commission report
    Route::get('/earnings', [\App\Http\Controllers\Consultant\EarningController::class, 'index'])->name('earnings.index');

    // The locked page a suspended consultant is redirected to — see
    // EnforceConsultantFinanceStatus. Deliberately reachable while suspended.
    Route::get('/suspended', \App\Http\Controllers\Consultant\SuspendedController::class)->name('suspended');
});

/*
|--------------------------------------------------------------------------
| Student LMS — consultants keep LMS access for their own enrollments
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:student|consultant'])->prefix('student')->name('student.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/my-courses', [DashboardController::class, 'courses'])->name('courses');
    Route::get('/certificates', [DashboardController::class, 'certificates'])->name('certificates');
    Route::get('/certificates/{certificate}/view', [\App\Http\Controllers\Student\CertificateController::class, 'show'])->name('certificates.view');

    // Course player + progress
    Route::get('/learn/{course}', [\App\Http\Controllers\Student\LearnController::class, 'index'])->name('learn');
    Route::get('/learn/{course}/{lesson}', [\App\Http\Controllers\Student\LearnController::class, 'lesson'])->name('learn.lesson');
    Route::post('/learn/{course}/{lesson}/complete', [\App\Http\Controllers\Student\LearnController::class, 'complete'])->name('learn.complete');

    // Course video. The file address is never in the page: the player asks for
    // a short-lived signed URL the moment it plays (which is also what opens
    // the viewing window), then streams it with Range support so seeking works.
    // See App\Http\Controllers\Student\LessonVideoController.
    Route::post('/learn/{course}/{lesson}/video-ticket', [\App\Http\Controllers\Student\LessonVideoController::class, 'ticket'])->name('lesson.video.ticket');
    Route::get('/learn/{course}/{lesson}/video', [\App\Http\Controllers\Student\LessonVideoController::class, 'show'])->name('lesson.video');
    Route::post('/learn/{course}/{lesson}/request-again', [\App\Http\Controllers\Student\LessonVideoController::class, 'requestAgain'])->name('lesson.video.request');

    // Assignments
    Route::get('/assignments', [\App\Http\Controllers\Student\AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/{assignment}', [\App\Http\Controllers\Student\AssignmentController::class, 'show'])->name('assignments.show');
    Route::post('/assignments/{assignment}/submit', [\App\Http\Controllers\Student\AssignmentController::class, 'submit'])->name('assignments.submit');

    // Exams & results
    Route::get('/exams', [\App\Http\Controllers\Student\ExamController::class, 'index'])->name('exams.index');
    Route::get('/results', [\App\Http\Controllers\Student\ExamController::class, 'results'])->name('exams.results');
    Route::get('/exams/{exam}/take', [\App\Http\Controllers\Student\ExamController::class, 'take'])->name('exams.take');
    Route::post('/exams/{exam}/submit', [\App\Http\Controllers\Student\ExamController::class, 'submit'])->name('exams.submit');
    // 2026-10-18: answers are kept as they are picked (what counts if time runs out), and the marked paper with explanations.
    Route::post('/exams/{exam}/save', [\App\Http\Controllers\Student\ExamController::class, 'save'])->name('exams.save');
    Route::get('/exams/{exam}/review', [\App\Http\Controllers\Student\ExamController::class, 'review'])->name('exams.review');
});
