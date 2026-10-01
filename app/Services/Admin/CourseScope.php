<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Support\Permissions\AdminSections;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which courses a Dashboard account may see (D-074).
 *
 * A role's `course_scope` is `all` or `assigned`. An account is scoped only
 * when none of its roles is `all` (super admins never are). A scoped account
 * sees the courses its linked instructor teaches (`admins.instructor_id` ->
 * `courses_instructors`) and nothing else: their cohorts, learners,
 * submissions, evaluations, certificates and the analytics built from them.
 * A scoped account with no instructor link sees no courses at all: deny by
 * default.
 *
 * Bound per request (AppServiceProvider), so the lookups below run once.
 */
class CourseScope
{
    /** @var array<int,string> */
    private array $scopes = [];

    /** @var array<int,list<int>> */
    private array $courses = [];

    public function scopeOf(Admin $admin): string
    {
        $key = (int) $admin->getKey();

        return $this->scopes[$key] ??= $this->resolveScope($admin);
    }

    /** True when `$principal` is an Admin limited to their own courses. */
    public function isScoped(mixed $principal): bool
    {
        return $principal instanceof Admin && $this->scopeOf($principal) === 'assigned';
    }

    /**
     * The course ids `$principal` may see, or null for "every course".
     *
     * @return list<int>|null
     */
    public function courseIds(mixed $principal): ?array
    {
        if (! $this->isScoped($principal)) {
            return null;
        }

        /** @var Admin $principal */
        $key = (int) $principal->getKey();

        return $this->courses[$key] ??= $principal->instructor_id === null
            ? []
            : DB::table('courses_instructors')
                ->where('instructor_id', $principal->instructor_id)
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
    }

    public function allowsCourse(mixed $principal, int|string|null $courseId): bool
    {
        $ids = $this->courseIds($principal);

        return $ids === null || ($courseId !== null && in_array((int) $courseId, $ids, true));
    }

    /** 404 for a course outside the scope: its existence is not disclosed. */
    public function assertCourse(mixed $principal, int|string|null $courseId): void
    {
        if (! $this->allowsCourse($principal, $courseId)) {
            abort(404, __('messages.course_out_of_scope'));
        }
    }

    /**
     * Limit `$query` to in-scope courses through `$column` (a course id
     * column). No-op for unscoped principals.
     */
    public function constrain(EloquentBuilder|QueryBuilder $query, mixed $principal, string $column = 'courses.id'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->courseIds($principal);
        if ($ids !== null) {
            $query->whereIn($column, $ids === [] ? [0] : $ids);
        }

        return $query;
    }

    /**
     * Limit a learner (users) query to learners enrolled in an in-scope
     * course, through `$column` (a users id column).
     */
    public function constrainLearners(EloquentBuilder|QueryBuilder $query, mixed $principal, string $column = 'users.id'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->courseIds($principal);
        if ($ids !== null) {
            $query->whereIn($column, DB::table('users_courses')->select('user_id')->whereIn('course_id', $ids === [] ? [0] : $ids));
        }

        return $query;
    }

    public function allowsLearner(mixed $principal, int|string|null $userId): bool
    {
        $ids = $this->courseIds($principal);
        if ($ids === null) {
            return true;
        }

        return $userId !== null && $ids !== []
            && DB::table('users_courses')->where('user_id', (int) $userId)->whereIn('course_id', $ids)->exists();
    }

    public function assertLearner(mixed $principal, int|string|null $userId): void
    {
        if (! $this->allowsLearner($principal, $userId)) {
            abort(404, __('messages.learner_out_of_scope'));
        }
    }

    /**
     * Route-level IDOR guard for scoped accounts, run by AdminSectionMiddleware
     * on every admin route: each route parameter that names a course, or a
     * record that belongs to one, must resolve to an in-scope course; each
     * learner parameter to a learner of one. Anything else is a 404.
     */
    public function assertRoute(\Illuminate\Http\Request $request): void
    {
        $principal = $request->user();
        $route = $request->route();
        if ($route === null || ! $this->isScoped($principal)) {
            return;
        }

        $uri = $route->uri();

        foreach ($route->parameters() as $name => $value) {
            $id = is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value;
            if (! is_scalar($id)) {
                continue;
            }

            match (true) {
                in_array($name, ['course', 'courseId'], true)
                    => $this->assertCourse($principal, $id),
                in_array($name, ['learner', 'userId'], true)
                    => $this->assertLearner($principal, $id),
                $name === 'quiz'
                    => $this->assertCourse($principal, $this->courseOf('course_exams', $id)),
                $name === 'userExam'
                    => $this->assertCourse($principal, $this->courseOf('user_exams', $id)),
                $name === 'certificate'
                    => $this->assertCourse($principal, $this->courseOf('user_certificates', $id)),
                $name === 'assignment' && str_starts_with($uri, 'api/v1/admin/assignments')
                    => $this->assertCourse($principal, $this->courseOf('course_assignments', $id)),
                $name === 'submission' && str_starts_with($uri, 'api/v1/admin/quizzes')
                    => $this->assertCourse($principal, $this->courseOf('user_exams', $id)),
                $name === 'submission' && str_starts_with($uri, 'api/v1/admin/assignments')
                    => $this->assertCourse($principal, DB::table('user_course_assignments as s')
                        ->join('course_assignments as a', 'a.id', '=', 's.course_assignment_id')
                        ->where('s.id', $id)->value('a.course_id')),
                $name === 'session' && str_starts_with($uri, 'api/v1/admin/course-sessions')
                    => $this->assertCourse($principal, $this->courseOf('course_sessions', $id)),
                $name === 'question' && str_starts_with($uri, 'api/v1/lecture-questions')
                    => $this->assertCourse($principal, $this->courseOf('course_lecture_questions', $id)),
                default => null,
            };
        }
    }

    private function courseOf(string $table, int|string $id): ?int
    {
        $courseId = DB::table($table)->where('id', $id)->value('course_id');

        return $courseId === null ? null : (int) $courseId;
    }

    private function resolveScope(Admin $admin): string
    {
        $roles = $admin->relationLoaded('roles') ? $admin->roles : $admin->roles()->get();

        if ($roles->contains(fn ($role) => AdminSections::isSuperAdminRole((string) $role->name))) {
            return 'all';
        }

        if ($roles->isEmpty()) {
            return 'assigned';
        }

        return $roles->contains(fn ($role) => ($role->course_scope ?? 'all') !== 'assigned') ? 'all' : 'assigned';
    }
}
