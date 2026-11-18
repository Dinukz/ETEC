<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages a CONSULTANT may open only when an admin has granted them (2026-09-22).
 *
 * Usage: ->middleware('consultant.page:admissions'). See Consultant::PAGE_ACCESS.
 *
 * Only a user whose primary role is consultant is checked — staff, lecturers and
 * group supporters reach these routes through their own role and are unaffected.
 */
class ConsultantPageAccess
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $user = $request->user();

        if ($user && $user->primaryRole() === 'consultant') {
            abort_unless(
                $user->consultant?->hasPageAccess($page),
                403,
                'You do not have access to this page. Ask an administrator to turn it on for you.',
            );
        }

        return $next($request);
    }
}
