<?php

namespace App\Repositories\Eloquents;

use App\Models\Course;
use App\Models\UsersCourse;
use App\Repositories\Contracts\UserEnrollmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserEnrollmentRepository implements UserEnrollmentRepositoryInterface
{
    /**
     * Enrolments of one course with each learner's lecture progress, for the
     * Course Details Learners tab (Figma 2266:129915) and the cohort learners
     * modal (2276:133999).
     *
     * Progress is a correlated sub-select, so one query returns every row and
     * the status filter (D-059 bands) runs in SQL before paging.
     *
     * @param  array{search?:string, status?:string, group_id?:int}  $filters
     */
    public function paginateForCourse(Course $course, int $perPage, array $filters = []): LengthAwarePaginator
    {
        [$progressSql, $bindings] = $this->progressExpression($course);

        $query = UsersCourse::query()
            ->select('users_courses.*')
            ->selectRaw("{$progressSql} AS progress_percent", $bindings)
            ->with(['user:id,name,name_en,name_ar,machine_code,status', 'group'])
            ->where('users_courses.course_id', $course->id)
            ->when($filters['group_id'] ?? null, fn ($q, $groupId) => $q->where('users_courses.group_id', $groupId))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                    ->where('name', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_ar', 'like', $like)
                    ->orWhere('machine_code', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'completed'   => $q->whereRaw("{$progressSql} >= 100", $bindings),
                'in_progress' => $q->whereRaw("{$progressSql} > 0", $bindings)->whereRaw("{$progressSql} < 100", $bindings),
                default       => $q->whereRaw("{$progressSql} = 0", $bindings),
            })
            ->orderByDesc('users_courses.id');

        return $query->paginate($perPage);
    }

    /** Enrolled learners with progress above 0 and below 100 (the header's "N Active", D-059). */
    public function countInProgress(Course $course): int
    {
        [$progressSql, $bindings] = $this->progressExpression($course);

        return UsersCourse::query()
            ->where('course_id', $course->id)
            ->whereRaw("{$progressSql} > 0", $bindings)
            ->whereRaw("{$progressSql} < 100", $bindings)
            ->count();
    }

    /**
     * FLOOR(completed lectures * 100 / total lectures) for users_courses.user_id,
     * with its bindings. Lecture ids are bound, never interpolated. A course
     * without lectures has no progress to measure: 0.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function progressExpression(Course $course): array
    {
        $lectureIds = $course->lectures()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $total      = count($lectureIds);

        if ($total === 0) {
            return ['0', []];
        }

        $placeholders = implode(',', array_fill(0, $total, '?'));

        return [
            "FLOOR((SELECT COUNT(*) FROM user_lecture_progress
                WHERE user_lecture_progress.user_id = users_courses.user_id
                  AND user_lecture_progress.completed = 1
                  AND user_lecture_progress.lecture_id IN ({$placeholders})) * 100 / ?)",
            array_merge($lectureIds, [$total]),
        ];
    }

    public function syncUsers(Course $course, array $userIds, ?int $groupId): void
    {
        $data = [];
        foreach ($userIds as $userId) {
            $data[$userId] = ['group_id' => $groupId];
        }
        $course->users()->syncWithoutDetaching($data);
    }

    public function delete(UsersCourse $enrollment): void
    {
        $enrollment->delete();
    }
}
