<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ⚠️ NOT WHILE A SUPER ADMIN IS LOGGED IN AS THIS USER (2026-09-14). They
        // are there to look, and making them set the user's password would change
        // it under the user. The flag stays for the user's own next login.
        if ($request->hasSession() && $request->session()->has(\App\Http\Controllers\Dashboard\ImpersonationController::SESSION_KEY)) {
            return $next($request);
        }

        // Allow the profile page itself plus every profile action, so a forced
        // user can request their password-reset email AND still fill in their
        // profile. Everything else is blocked until the password is changed.
        //
        // ⚠️ consultant.students.* AND checkout.* ARE ON THIS LIST ON PURPOSE.
        // The comment above used to claim they were, but they were not, so a
        // consultant carrying the flag was bounced to /profile every time they
        // opened Student Registration or bought a course - and the "set a new
        // password" flash disappears after six seconds, so it read as "the page
        // does not load". 49 of 364 consultants were stuck like that, which is
        // why only SOME of them reported it. Reported 2026-09-11.
        //
        // Registering a student is how a consultant earns; it is not worth
        // blocking over a password they can be nagged about on any other click.
        $allowed = $request->routeIs(
            'profile.edit', 'profile.update', 'profile.avatar',
            'profile.password.email',
            'password.reset', 'password.update', 'logout',
            'consultant.students.*',
            'consultant.payments.*',
            'checkout.*',
        );

        if ($user && $user->password_must_change && ! $allowed) {
            return redirect()->route('profile.edit')->with('status', 'Please set a new password before continuing.');
        }

        return $next($request);
    }
}
