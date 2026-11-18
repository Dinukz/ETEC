<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Spatie Permission middleware aliases — enables route->middleware('role:admin') etc.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Pages a consultant opens only when granted (2026-09-22).
            'consultant.page' => \App\Http\Middleware\ConsultantPageAccess::class,
        ]);

        // Forces a redirect to /profile until a temp-password account changes it.
        // EnforceConsultantFinanceStatus MUST come after it, so a suspended
        // consultant who also must change their password lands on the same
        // profile.edit page either way, rather than the two middlewares
        // fighting over where to redirect.
        $middleware->web(append: [
            \App\Http\Middleware\ForcePasswordChange::class,
            \App\Http\Middleware\EnforceConsultantFinanceStatus::class,
            // The daily jobs Hostinger's missing cron never ran (2026-11-11) —
            // started once a day, after a page has already been sent.
            \App\Http\Middleware\RunDailyHousekeeping::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Spatie throws UnauthorizedException — render a friendly 403 page instead of a stack trace.
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You do not have permission to access this resource.'], 403);
            }

            return response()->view('errors.403', [], 403);
        });
    })->create();
