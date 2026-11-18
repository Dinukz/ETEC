<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit()
    {
        $user = auth()->user();

        return view('dashboard.profile', ['user' => $user, 'consultant' => $user->consultant]);
    }

    /** Avatar-only endpoint for the profile-picture modal — returns JSON so the page never reloads. */
    public function updateAvatar(Request $request)
    {
        $data = $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $user = auth()->user();
        $user->update(['avatar' => $request->file('avatar')->store('avatars', 'public')]);

        return response()->json(['avatar_url' => route('media.show', $user->avatar)]);
    }

    /**
     * A single form covers everything on the profile page. The first save
     * locks every field except the avatar — protects bank/beneficiary details
     * from being silently swapped after a consultant starts getting paid out.
     * Admin can lift the lock from the Edit User page if a genuine correction
     * is needed.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $consultant = $user->consultant;

        // A locked profile is read-only until an admin unlocks it (Edit User page).
        if ($user->profile_locked_at) {
            $msg = 'Your profile is locked. Contact an admin if a correction is needed.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ];

        if ($consultant) {
            $rules += [
                'nic' => ['nullable', 'string', 'max:20'],
                'date_of_birth' => ['nullable', 'date', 'before:today'],
                'gender' => ['nullable', 'in:male,female,other'],
                'address' => ['nullable', 'string', 'max:300'],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'bank_account_name' => ['nullable', 'string', 'max:120'],
                'bank_account_number' => ['nullable', 'string', 'max:40'],
                'bank_branch' => ['nullable', 'string', 'max:120'],
                'swift_code' => ['nullable', 'string', 'max:20'],
                'account_proof' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
                'beneficiary_name' => ['nullable', 'string', 'max:120'],
                'beneficiary_relationship' => ['nullable', 'string', 'max:80'],
                'beneficiary_nic' => ['nullable', 'string', 'max:20'],
                'beneficiary_date_of_birth' => ['nullable', 'date', 'before:today'],
                'beneficiary_phone' => ['nullable', 'string', 'max:40'],
            ];
        }

        $data = $request->validate($rules);

        if ($data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone'])));
        $user->save();

        if ($consultant) {
            if ($request->hasFile('account_proof')) {
                $data['account_proof_path'] = $request->file('account_proof')->store('consultants/account-proof', 'public');
            }

            $consultant->update(array_intersect_key($data, array_flip([
                'nic', 'date_of_birth', 'gender', 'address',
                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'swift_code', 'account_proof_path',
                'beneficiary_name', 'beneficiary_relationship', 'beneficiary_nic', 'beneficiary_date_of_birth', 'beneficiary_phone',
            ])));

            // Lock only once every required detail is filled in — partial saves
            // stay editable so the consultant can come back and finish. This is
            // the same condition that flips the "Profile Complete" badge, so the
            // two are always consistent.
            if ($consultant->profile_complete) {
                $user->update(['profile_locked_at' => now()]);
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'locked' => (bool) $user->profile_locked_at,
                'profile_complete' => (bool) ($consultant?->profile_complete),
            ]);
        }

        return back()->with('success', 'Profile updated successfully.');
    }

    /** Emails the logged-in user their own password-reset link — the only way to change a password from this page. */
    public function sendPasswordResetLink()
    {
        $status = Password::sendResetLink(['email' => auth()->user()->email]);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('success', 'A password reset link has been sent to your email.')
            : back()->with('error', __($status));
    }
}
