<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the authenticated principal to hold one of the given permissions.
 *
 * DB-01 (Critical): the `view-*` admin matrix was enforced only in the Angular
 * guard and sidebar. Server-side nothing checked it — RoleMiddleware tests only
 * `$user instanceof Admin`.
 *
 * Why this exists instead of Spatie's own PermissionMiddleware
 * -----------------------------------------------------------
 * Spatie's middleware resolves the principal with `Auth::guard($guard)->user()`.
 * This project never populates an Auth guard: AuthenticationMiddleware
 * validates the Sanctum token and then calls only
 *
 *     $request->setUserResolver(fn () => $tokenable);
 *
 * so `Auth::guard(...)->user()` is always null and Spatie's middleware refuses
 * every request — including one from an admin who genuinely holds the
 * permission. That was observed directly: gating a route with
 * `permission:view-roles` produced 403 for an admin with `view-roles`.
 *
 * Rather than reshape the app's authentication to suit the package, this reads
 * `$request->user()` — the same source RoleMiddleware, the controllers and the
 * resources all use — and defers the actual check to Spatie's `HasRoles` trait
 * on the model, so the permission data and caching stay Spatie's.
 *
 * Usage: `permission:view-roles` or `permission:view-roles|view-controllers`
 * (pipe = any-of), matching Spatie's own syntax so route files read the same.
 */
class AdminPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $principal = $request->user();

        if ($principal === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.unauthenticated'),
            ], 401);
        }

        // Accept both `permission:a,b` and Spatie's `permission:a|b`.
        $required = [];
        foreach ($permissions as $chunk) {
            foreach (explode('|', $chunk) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $required[] = $name;
                }
            }
        }

        if ($required === []) {
            return $next($request);
        }

        // Models that do not use Spatie's HasRoles cannot hold permissions.
        if (! method_exists($principal, 'hasPermissionTo')) {
            return $this->forbidden();
        }

        foreach ($required as $name) {
            try {
                if ($principal->hasPermissionTo($name)) {
                    return $next($request);
                }
            } catch (\Throwable) {
                // An unknown permission name must not 500 the request; treat it
                // as "not held" and fall through to the 403 below.
                continue;
            }
        }

        return $this->forbidden();
    }

    private function forbidden(): Response
    {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.forbidden'),
        ], 403);
    }
}
