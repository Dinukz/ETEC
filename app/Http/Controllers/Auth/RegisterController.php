<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function show(Request $request)
    {
        // Capture the consultant referral code from ?ref= links; it is applied
        // to the student profile on registration and to checkout payments.
        if ($request->filled('ref')) {
            session(['referral_code' => strtoupper(trim($request->query('ref')))]);
        }

        return view('auth.register');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email', new \App\Rules\RealEmailAddress],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);

            $user->assignRole('student');

            $referrer = session('referral_code')
                ? Consultant::where('referral_code', session('referral_code'))
                    ->where('status', '!=', 'suspended')
                    ->notFinanceSuspended()
                    ->first()
                : null;

            Student::create([
                'user_id' => $user->id,
                'student_code' => \App\Services\NumberPool::format('SRN', \App\Services\NumberPool::take()),
                'consultant_id' => $referrer?->id,
                'status' => 'active',
                'enrolled_at' => now(),
            ]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        return redirect()->route($user->dashboardRoute())->with('success', 'Welcome to ETEC! Your account is ready.');
    }
}
