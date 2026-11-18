<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Boots each seeded role, requests every navigable route, and asserts none
 * return a 500. Catches bad route() names in Blade, missing view vars, and
 * controller errors that lint/route:list cannot see.
 */
class SmokeRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[DataProvider('roleRoutes')]
    public function test_role_routes_do_not_error(string $email, array $routeNames): void
    {
        $user = User::where('email', $email)->firstOrFail();

        // The four optional pages are granted per consultant since 2026-09-22;
        // grant them so this still smoke-tests every page a consultant can have.
        if ($user->hasRole('consultant') && $user->consultant) {
            $this->grantConsultantPages($user);
        }

        foreach ($routeNames as $name) {
            $url = $this->resolve($name);
            if ($url === null) {
                continue;
            }
            $response = $this->actingAs($user)->get($url);
            $this->assertNotSame(500, $response->getStatusCode(), "Route [{$name}] ({$url}) as [{$email}] returned 500");
            $this->assertContains($response->getStatusCode(), [200, 302], "Route [{$name}] ({$url}) as [{$email}] returned {$response->getStatusCode()}");
        }
    }

    protected function resolve(string $name): ?string
    {
        return match ($name) {
            'manage.courses.show', 'manage.courses.edit' => ($c = Course::first()) ? route($name, $c) : null,
            'manage.lessons.create' => ($c = Course::first()) ? route($name, $c) : null,
            'manage.assignments.show', 'manage.assignments.edit' => ($a = Assignment::first()) ? route($name, $a) : null,
            'manage.exams.edit', 'manage.exams.results' => ($e = Exam::first()) ? route($name, $e) : null,
            default => route($name),
        };
    }

    public static function roleRoutes(): array
    {
        return [
            'super_admin' => ['superadmin@etec.lk', [
                'super-admin.dashboard', 'users.index', 'users.create', 'manage.courses.index',
                'manage.courses.create', 'manage.assignments.index', 'manage.exams.index', 'manage.zoom.index',
                'payments.index', 'commissions.index', 'commissions.history', 'commissions.revenue',
                'groups.index', 'settings.index', 'bookings.index', 'bookings.all', 'bookings.create',
                'cms.blogs.index', 'cms.blogs.create', 'cms.testimonials.index', 'cms.faqs.index',
                'cms.pages.index', 'cms.messages.index', 'reports.admissions', 'reports.payments',
                'notifications.index',
            ]],
            'admin' => ['admin@etec.lk', [
                'admin.dashboard', 'users.index', 'manage.courses.index', 'payments.index',
                'commissions.index', 'groups.index', 'settings.index', 'bookings.index', 'bookings.all',
                'cms.blogs.index', 'reports.admissions', 'reports.payments',
            ]],
            'accountant' => ['accountant@etec.lk', [
                'accountant.dashboard', 'payments.index', 'commissions.index', 'commissions.history',
                'commissions.revenue', 'reports.admissions', 'reports.payments', 'bookings.index', 'bookings.all',
                'notifications.index',
            ]],
            'lecturer' => ['lecturer@etec.lk', [
                'lecturer.dashboard', 'manage.courses.index', 'manage.courses.create', 'manage.assignments.index',
                'manage.assignments.create', 'manage.exams.index', 'manage.exams.create', 'manage.zoom.index',
                'manage.zoom.create', 'notifications.index',
            ]],
            'consultant' => ['consultant@etec.lk', [
                'consultant.dashboard', 'consultant.students.create', 'consultant.students.pending',
                'consultant.students.index', 'consultant.groups.index', 'consultant.earnings.index',
                'reports.admissions', 'reports.payments', 'bookings.index', 'bookings.all', 'notifications.index',
            ]],
            'student' => ['student@etec.lk', [
                'student.dashboard', 'student.courses', 'student.assignments.index', 'student.exams.index',
                'student.exams.results', 'student.certificates', 'courses.index', 'notifications.index',
            ]],
        ];
    }

    public function test_public_pages_render(): void
    {
        Hall::query()->exists(); // ensure seed ran
        foreach (['home', 'about', 'consultant-program', 'faq', 'contact', 'courses.index', 'blog.index', 'certificates.verify'] as $name) {
            $response = $this->get(route($name));
            $this->assertNotSame(500, $response->getStatusCode(), "Public route [{$name}] returned 500");
        }
    }
}
