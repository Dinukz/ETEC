<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Super admin: log in as a user without their password, then come back.
 *
 * Asked for 2026-09-14: "super adminta withrk userge account ekat ethaninm log
 * wenn password nathuw ... ethkot usergen aha aha inn one na kelinm account
 * ekat log wela check kragnn puluwn issue eka mokkd kiyla" — see a problem
 * the way the user sees it, without asking them for anything.
 *
 * ⚠️ WHAT KEEPS THIS SAFE
 *   - POST only and CSRF-checked; super_admin on the route AND re-checked here.
 *   - Never into another super admin, never into yourself, never one inside
 *     another.
 *   - The way back is a value in the server-side session, not anything the
 *     browser sends, and leave() restores it only while that account is STILL
 *     a super admin — otherwise it signs out completely.
 *   - Both directions are written to the activity log: who, as whom, when.
 *   - The session is emptied on each switch, so nothing cached for one person
 *     follows the admin into the other — e.g. the finance marker
 *     EnforceConsultantFinanceStatus keeps in the session.
 *   - The user's last_login_at is left alone; only LoginController writes it.
 */
class ImpersonationController extends Controller
{
    /** Holds the super admin's own user id while they are logged in as somebody else. */
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user)
    {
        $admin = $request->user();

        abort_unless($admin?->isSuperAdmin(), 403, 'Only a super admin can log in as another user.');
        abort_if($request->session()->has(self::SESSION_KEY), 403, 'Return to your own account first.');

        if ($user->id === $admin->id) {
            return back()->with('error', 'That is your own account.');
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Another super admin\'s account cannot be opened from here.');
        }

        activity()
            ->causedBy($admin)
            ->performedOn($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('Super admin logged in as this user');

        $request->session()->flush();
        Auth::guard('web')->login($user);
        $request->session()->put(self::SESSION_KEY, $admin->id);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route($user->dashboardRoute());
    }

    public function leave(Request $request)
    {
        $adminId = $request->session()->get(self::SESSION_KEY);

        abort_unless($adminId, 403, 'You are not logged in as another user.');

        $user = $request->user();
        $admin = User::find($adminId);

        $request->session()->flush();

        // Back only into an account that is still a super admin; otherwise out.
        if (! $admin || ! $admin->isSuperAdmin()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        activity()
            ->causedBy($admin)
            ->performedOn($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('Super admin returned from this user');

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('users.edit', $user)->with('success', 'You are back in your own account.');
    }
}
