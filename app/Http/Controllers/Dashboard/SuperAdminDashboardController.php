<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class SuperAdminDashboardController extends Controller
{
    public function __invoke()
    {
        $stats = [
            'users' => User::count(),
            'students' => User::whereHas('student')->count(),
            'consultants' => User::whereHas('consultant')->count(),
            'courses' => Course::count(),
            'enrollments' => Enrollment::whereIn('status', ['active', 'completed'])->count(),
            'revenue' => (float) Payment::where('status', 'verified')->sum('amount'),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'commissions_paid' => (float) Commission::where('status', 'paid')->sum('amount'),
        ];

        $usersByRole = User::query()
            ->join('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('roles.name as role, count(*) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'role');

        $recentUsers = User::with('roles')->latest()->take(6)->get();

        $recentActivity = class_exists(Activity::class)
            ? Activity::with('causer')->latest()->take(8)->get()
            : collect();

        return view('dashboard.super-admin.index', compact('stats', 'usersByRole', 'recentUsers', 'recentActivity'));
    }
}
