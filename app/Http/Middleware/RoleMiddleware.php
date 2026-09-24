<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Instructor;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthenticated'),
            ], 401);
        }

        foreach ($roles as $role) {
            $normalized = strtolower($role);

            if ($normalized === 'admin' && $user instanceof Admin) {
                return $next($request);
            }

            if ($normalized === 'user' && $user instanceof User) {
                return $next($request);
            }

            // Instructors are a first-class principal — AuthenticationMiddleware
            // resolves User, Instructor or Admin — but this middleware only
            // understood the first and last, so `role:...,Instructor` silently
            // rejected them. Added for the B-10 submission download, which
            // instructors legitimately need.
            if ($normalized === 'instructor' && $user instanceof Instructor) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => 'error',
                'message' => __('messages.forbidden'),
        ], 403);
    }
}
