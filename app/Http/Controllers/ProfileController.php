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

        // ⚠️ EVERYTHING IS REQUIRED NOW — GOING-LIVE TIGHTENING (2026-09-06).
        //
        // These were `nullable` so the team could click through the form while
        // testing. Live, a half-filled profile is a consultant nobody can pay:
        // the bank and beneficiary details here are what the payout runs on.
        // Owner: "dan meka live yanna hadanne, eka nisa okkoma validation ekak
        // dala okkoma fill karana widiyata."
        //
        // ⚠️ TWO FIELDS ARE DELIBERATELY NOT REQUIRED, and both would break real
        // submissions if they were:
        //
        //   swift_code    — local Sri Lankan bank accounts do not have one. It
        //                   matters only for an international transfer, so
        //                   demanding it would lock out most consultants over a
        //                   field they cannot fill in truthfully.
        //   account_proof — a FILE. Required only when nothing is on record yet;
        //                   otherwise every later save (fixing a typo in a phone
        //                   number) would demand the document be uploaded again.
        //                   `required_without` reads the stored path for that.
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:40'],
        ];

        if ($consultant) {
            $rules += [
                'nic' => ['required', 'string', 'max:20'],
                'date_of_birth' => ['required', 'date', 'before:today'],
                'gender' => ['required', 'in:male,female,other'],
                'address' => ['required', 'string', 'max:300'],
                'bank_name' => ['required', 'string', 'max:120'],
                'bank_account_name' => ['required', 'string', 'max:120'],
                'bank_account_number' => ['required', 'string', 'max:40'],
                'bank_branch' => ['required', 'string', 'max:120'],
                'swift_code' => ['nullable', 'string', 'max:20'],
                'account_proof' => [
                    $consultant->account_proof_path ? 'nullable' : 'required',
                    'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240',
                ],
                'beneficiary_name' => ['required', 'string', 'max:120'],
                'beneficiary_relationship' => ['required', 'string', 'max:80'],
                'beneficiary_nic' => ['required', 'string', 'max:20'],
                'beneficiary_date_of_birth' => ['required', 'date', 'before:today'],
                'beneficiary_phone' => ['required', 'string', 'max:40'],
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
