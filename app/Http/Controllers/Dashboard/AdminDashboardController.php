<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;

class AdminDashboardController extends Controller
{
    public function __invoke()
    {
        $stats = [
            'users' => User::count(),
            'students' => User::whereHas('student')->count(),
            'courses' => Course::where('status', 'published')->count(),
            'enrollments' => Enrollment::whereIn('status', ['active', 'completed'])->count(),
            'revenue' => (float) Payment::where('status', 'verified')->sum('amount'),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'new_messages' => ContactMessage::where('status', 'new')->count(),
            'pending_consultants' => User::whereHas('consultant', fn ($q) => $q->where('status', 'pending'))->count(),
        ];

        $recentUsers = User::with('roles')->latest()->take(6)->get();

        $recentEnrollments = Enrollment::with(['user', 'course'])
            ->whereIn('status', ['active', 'completed'])
            ->latest('enrolled_at')
            ->take(6)
            ->get();

        $recentMessages = ContactMessage::latest()->take(5)->get();

        return view('dashboard.admin.index', compact('stats', 'recentUsers', 'recentEnrollments', 'recentMessages'));
    }
}
