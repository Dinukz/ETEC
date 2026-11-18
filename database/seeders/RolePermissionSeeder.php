<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Roles: super_admin | admin | accountant | lecturer | consultant | student
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // User & access management
            'users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage',
            // Students & consultants
            'students.view', 'students.manage',
            'consultants.view', 'consultants.manage', 'consultants.approve',
            // Courses / LMS authoring
            'courses.view', 'courses.create', 'courses.update', 'courses.delete', 'courses.publish',
            'lessons.manage', 'zoom.manage',
            'assignments.manage', 'assignments.grade',
            'exams.manage', 'exams.grade', 'certificates.issue',
            // Enrollments & learning
            'enrollments.view', 'enrollments.manage', 'lms.access',
            // Finance
            'payments.view', 'payments.verify', 'payments.reject',
            'commissions.view', 'commissions.manage', 'commissions.pay',
            'revenue.view',
            // Groups
            'groups.view', 'groups.manage',
            // Hall bookings
            'bookings.view', 'bookings.manage',
            // Platform
            'reports.view', 'reports.export', 'settings.manage', 'cms.manage',
            // ⚠️ GRANTED PER USER, NOT PER ROLE (2026-09-02).
            //
            // Lets a CONSULTANT see the admissions of the consultants below them,
            // instead of only their own accounts. Deliberately absent from the
            // `consultant` role below, so a consultant who signs up tomorrow has
            // it OFF and the whole feature is hidden from them — the owner's
            // requirement: "aluthin ena consultant lata me function eka block
            // krnn one nisa, user management eken access denn".
            //
            // An admin turns it on for one person from User Management, which is
            // what Spatie's per-user givePermissionTo() is for. Do NOT add it to
            // the consultant role to "make it work" — that hands it to everyone.
            'reports.downline',
            'notifications.send', 'activity.view', 'backups.manage', 'system.maintenance',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roles = [
            'super_admin' => Permission::all()->pluck('name')->all(),

            'admin' => [
                'users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage',
                'students.view', 'students.manage',
                'consultants.view', 'consultants.manage', 'consultants.approve',
                'courses.view', 'courses.create', 'courses.update', 'courses.delete', 'courses.publish',
                'lessons.manage', 'zoom.manage', 'assignments.manage', 'exams.manage', 'certificates.issue',
                'enrollments.view', 'enrollments.manage',
                'payments.view', 'commissions.view', 'commissions.manage', 'revenue.view',
                'groups.view', 'groups.manage',
                'bookings.view', 'bookings.manage',
                'reports.view', 'reports.export', 'reports.downline', 'settings.manage', 'cms.manage',
                'notifications.send', 'activity.view',
            ],

            'accountant' => [
                'students.view', 'consultants.view',
                'payments.view', 'payments.verify', 'payments.reject',
                'commissions.view', 'commissions.manage', 'commissions.pay',
                'revenue.view', 'groups.view',
                'bookings.view', 'bookings.manage',
                'reports.view', 'reports.export',
            ],

            'lecturer' => [
                'courses.view', 'courses.update', 'lessons.manage', 'zoom.manage',
                'assignments.manage', 'assignments.grade', 'exams.manage', 'exams.grade',
                'certificates.issue', 'enrollments.view', 'notifications.send',
                'bookings.view', 'bookings.manage',
            ],

            // 2026-09-17: the Payments tab (view only) and the Admission Report.
            // On the live host (no CLI) UserManagementController creates it on
            // first use instead.
            'group_supporter' => [
                'payments.view', 'reports.view',
            ],

            'consultant' => [
                'students.view', 'groups.view', 'commissions.view', 'enrollments.view', 'lms.access',
                'bookings.view', 'bookings.manage',
            ],

            'student' => [
                'enrollments.view', 'lms.access',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
