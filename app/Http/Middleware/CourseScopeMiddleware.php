<?php

namespace App\Http\Middleware;

use App\Services\Admin\CourseScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Course scope (D-074) outside the `section:` gate:
 *
 *  - `course.scope` on routes learners share with the Dashboard (the course
 *    and course-content reads): an out-of-scope course is a 404;
 *  - `course.scope:deny` on legacy endpoints that cannot be limited to a set
 *    of courses: an account limited to its own courses is refused outright.
 *
 * For anyone but a scoped Dashboard account it does nothing.
 */
class CourseScopeMiddleware
{
    public function __construct(private readonly CourseScope $scope) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($mode === 'deny' && $this->scope->isScoped($request->user())) {
            return response()->json(['status' => 'error', 'message' => __('messages.section_org_wide')], 403);
        }

        $this->scope->assertRoute($request);

        return $next($request);
    }
}
