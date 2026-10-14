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
     * Progress (cohort modules, rounded as on the Website) and completion
     * (the one rule, CourseCompletion, B-171) are computed once per row in a
     * derived table; the status filter and paging run on those columns.
     *
     * @param  array{search?:string, status?:string, group_id?:int}  $filters
     */
    public function paginateForCourse(Course $course, int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = UsersCourse::query()
            ->fromSub($this->rowsWithProgress($course), 'users_courses')
            ->select('users_courses.*')
            ->with(['user:id,name,name_en,name_ar,machine_code,status,image,hr_image_path', 'group'])
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
                'completed'   => $q->where('users_courses.is_completed', 1),
                'in_progress' => $q->where('users_courses.is_completed', 0)->where('users_courses.progress_percent', '>', 0),
                default       => $q->where('users_courses.is_completed', 0)->where('users_courses.progress_percent', 0),
            })
            ->orderByDesc('users_courses.id');

        return $query->paginate($perPage);
    }

    /** Enrolled learners who started and have not completed (the header's "N Active", D-059). */
    public function countInProgress(Course $course): int
    {
        return DB::query()
            ->fromSub($this->rowsWithProgress($course), 'p')
            ->where('p.is_completed', 0)
            ->where('p.progress_percent', '>', 0)
            ->count();
    }

    /**
     * The course's enrolments with `progress_percent` and `is_completed`,
     * each computed once per row.
     */
    private function rowsWithProgress(Course $course): \Illuminate\Database\Query\Builder
    {
        [$progressSql, $bindings] = $this->progressExpression($course);

        return DB::table('users_courses')
            ->select('users_courses.*')
            ->selectRaw("{$progressSql} AS progress_percent", $bindings)
            ->selectRaw('('.\App\Support\CourseCompletion::existsSql('users_courses.user_id', 'users_courses.course_id').') AS is_completed')
            ->where('users_courses.course_id', $course->id);
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
        if (! $course->lectures()->exists()) {
            // A course without modules: progress is attendance of the
            // learner's cohort sessions (0 when it has none), D-094.
            $attended = 'SELECT COUNT(DISTINCT pa.session_id) FROM attendances pa WHERE pa.user_id = users_courses.user_id AND pa.course_id = ? AND pa.session_id IS NOT NULL';
            $sessions = 'SELECT COUNT(*) FROM course_sessions ps WHERE ps.section_id = users_courses.group_id';

            return [
                "COALESCE(LEAST(100, ROUND(({$attended}) * 100 / NULLIF(({$sessions}), 0))), 0)",
                [(int) $course->id],
            ];
        }

        // Each learner's own modules: those of their cohort sheet (D-089).
        $total = 'SELECT COUNT(*) FROM course_lectures pt WHERE pt.course_id = ? AND '
            .\App\Support\CohortModules::requiredSql('users_courses.user_id', 'pt');
        $done  = 'SELECT COUNT(*) FROM user_lecture_progress pu JOIN course_lectures pd ON pd.id = pu.lecture_id
                  WHERE pu.user_id = users_courses.user_id AND pu.completed = 1 AND pd.course_id = ? AND '
            .\App\Support\CohortModules::requiredSql('users_courses.user_id', 'pd');

        return [
            // Rounded, as the Website rounds it (B-171).
            "COALESCE(ROUND(({$done}) * 100 / NULLIF(({$total}), 0)), 0)",
            [(int) $course->id, (int) $course->id],
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
