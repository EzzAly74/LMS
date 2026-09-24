<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Instructor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the authenticated learner to be enrolled in the route's course.
 *
 * B-15 (Medium, effectively an IDOR): the learner course-player endpoints
 * carried no enrolment or visibility check at all. `LearnerCoursePlayerService`
 * contained no reference to enrolment, and the routes are `auth.user` only, so
 * any authenticated learner could read the outline, lecture content and media
 * URLs, quizzes and assignments of any course — including courses they were
 * never enrolled in, and inactive or draft ones.
 *
 * Implemented as middleware rather than a check per action because the same
 * rule applies across the outline, lecture, progress, exam-submission,
 * certificate-status and evaluation routes. One rule, one place.
 *
 * Admins and Instructors pass through: they legitimately inspect course content
 * they are not "enrolled" in. Instructor-to-course ownership is a separate gap
 * (there is no ownership model yet — see DB-01 / plan item A3) and is
 * deliberately not invented here.
 */
class EnsureEnrolledInCourse
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        if ($principal === null) {
            abort(401);
        }

        // Staff are not enrolled in courses; their access is governed by the
        // admin permission model, not by enrolment.
        if ($principal instanceof Admin || $principal instanceof Instructor) {
            return $next($request);
        }

        $course = $request->route('course');

        // Nothing to scope against — let the route's own model binding 404.
        if (! $course instanceof Course) {
            return $next($request);
        }

        abort_unless(
            $course->users()->whereKey($principal->getKey())->exists(),
            403,
            __('messages.course_not_enrolled'),
        );

        return $next($request);
    }
}
