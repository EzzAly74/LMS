<?php

namespace App\Services\Admin;

use App\Http\Traits\HasFile;
use App\Models\User;
use App\Support\CourseCompletion;
use App\Support\LocalizedJson;
use App\Support\QualificationHolding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;


/**
 * Admin-side view of a single learner (Figma 2181:115043).
 *
 * The learner-facing `learner/profile/*` and `my/*` endpoints are all scoped to
 * the logged-in user, so an admin cannot reuse any of them — this is genuinely
 * new surface, as 02-figma-map.md recorded.
 *
 * ── On "completed" ───────────────────────────────────────────────────
 * This service used to note that the codebase carried two contradictory
 * definitions of a completed course, and picked the exam-based one so that
 * this screen agreed with the list it drills into. That was B-104.
 *
 * It is resolved: App\Support\CourseCompletion is now the single definition
 * project-wide, and it is the exam-based one. This service delegates to it
 * rather than repeating the status literals.
 */
class AdminLearnerProfileService
{
    use HasFile;

    /** Profile card + the five summary tiles. */
    public function profile(User $learner): array
    {
        $enrolled = $this->inScope(DB::table('users_courses'), 'users_courses.course_id')->where('user_id', $learner->id);

        $passedCourseIds = CourseCompletion::courseIdsFor($learner->id);

        $enrolledCount  = (clone $enrolled)->count();
        $completedCount = $passedCourseIds->count();

        $lastQuiz = $this->inScope(DB::table('user_exams'), 'user_exams.course_id')
            ->where('user_id', $learner->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first(['user_degree', 'max_score', 'total_score', 'status', 'submitted_at']);

        $lastActiveCourse = $this->inScope(DB::table('users_courses'), 'users_courses.course_id')
            ->join('courses', 'users_courses.course_id', '=', 'courses.id')
            ->where('users_courses.user_id', $learner->id)
            ->orderByDesc('users_courses.updated_at')
            ->first(['courses.id', 'courses.title']);

        // Qualifications this learner holds, by the one project rule
        // (App\Support\QualificationHolding, D-056). This counted any
        // qualification linked to ANY one passed course, and missed direct
        // grants - so the tile disagreed with the job-title detail.
        $earnedQualifications = DB::table('users')
            ->crossJoin('qualification_skills as eq')
            ->where('users.id', $learner->id)
            ->whereRaw(QualificationHolding::holdsSql('users.id', 'eq.id'))
            ->count();

        $learner->loadMissing('jobTitle');

        return [
            'profile' => [
                'id'          => $learner->id,
                'name'        => $learner->getLocalizedName(),
                'job_title'   => $learner->jobTitle?->getLocalizedName(),
                'employee_id' => $learner->machine_code,
                'email'       => $learner->email,
                'department'  => $learner->department_name,
                // A URL the Dashboard can load, like every other resource. This
                // returned the raw disk path, which no <img> can resolve.
                'image_url'   => $learner->image ? $this->getFileUrl($learner->image) : null,
                'status'      => $learner->status ?? 'active',
                'last_active_at'     => $learner->last_active_at,
                // Read with the query builder, so the stored JSON must be
                // resolved here; it reached the card as {"ar":...,"en":...}.
                'last_active_course' => LocalizedJson::pick($lastActiveCourse?->title),
            ],

            // The five tiles named in the Figma capture: "Completed Courses 1,
            // Active Courses 2, Active Course 88.3%, Last Quiz 100/105,
            // Earned Qualification 1".
            'tiles' => [
                'completed_courses' => $completedCount,
                'active_courses'    => max(0, $enrolledCount - $completedCount),
                'active_course_progress_percent' => $this->activeCourseProgress($learner, $passedCourseIds->all()),
                'last_quiz' => $lastQuiz === null ? null : [
                    'score' => $lastQuiz->user_degree !== null ? (float) $lastQuiz->user_degree : null,
                    'max'   => (float) ($lastQuiz->max_score ?? $lastQuiz->total_score ?? 0),
                    'status' => $lastQuiz->status,
                    'submitted_at' => $lastQuiz->submitted_at,
                ],
                'earned_qualifications' => $earnedQualifications,
            ],
        ];
    }

    /**
     * Lecture-completion percentage across the learner's not-yet-passed
     * courses — the "Active Course 88.3%" tile.
     *
     * Returns null rather than 0 when there is nothing in progress, so the UI
     * can render the design's "—" instead of an honest-looking zero.
     */
    private function activeCourseProgress(User $learner, array $passedCourseIds): ?float
    {
        $activeCourseIds = $this->inScope(DB::table('users_courses'), 'users_courses.course_id')
            ->where('user_id', $learner->id)
            ->when($passedCourseIds !== [], fn ($q) => $q->whereNotIn('course_id', $passedCourseIds))
            ->pluck('course_id');

        if ($activeCourseIds->isEmpty()) {
            return null;
        }

        // course_lectures carries course_id directly, so no join is needed.
        $totalLectures = DB::table('course_lectures')
            ->whereIn('course_id', $activeCourseIds)
            ->count();

        if ($totalLectures === 0) {
            return null;
        }

        $completedLectures = DB::table('user_lecture_progress')
            ->join('course_lectures', 'user_lecture_progress.lecture_id', '=', 'course_lectures.id')
            ->where('user_lecture_progress.user_id', $learner->id)
            ->where('user_lecture_progress.completed', true)
            ->whereIn('course_lectures.course_id', $activeCourseIds)
            ->count();

        return round($completedLectures * 100 / $totalLectures, 1);
    }

    /**
     * "Active & Completed Courses" table: course + cohort, attended, absent,
     * status, progress, started on, ended on.
     */
    public function courses(User $learner, int $perPage): LengthAwarePaginator
    {
        $passedCourseIds = $this->inScope(DB::table('user_exams'), 'user_exams.course_id')
            ->where('user_id', $learner->id)
            ->whereRaw('LOWER(COALESCE(status, "")) IN (?, ?)', ['passed', 'completed'])
            ->distinct()
            ->pluck('course_id')
            ->all();

        // Sessions scheduled for the learner's cohort on this course.
        $scheduled = DB::table('course_sessions')
            ->selectRaw('COUNT(*)')
            ->whereColumn('course_sessions.course_id', 'users_courses.course_id')
            ->whereColumn('course_sessions.section_id', 'users_courses.group_id');

        $attended = DB::table('attendances')
            ->selectRaw('COUNT(DISTINCT attendances.session_id)')
            ->whereColumn('attendances.user_id', 'users_courses.user_id')
            ->whereColumn('attendances.course_id', 'users_courses.course_id');

        $page = $this->inScope(DB::table('users_courses'), 'users_courses.course_id')
            ->join('courses', 'users_courses.course_id', '=', 'courses.id')
            ->leftJoin('course_sections', 'users_courses.group_id', '=', 'course_sections.id')
            ->where('users_courses.user_id', $learner->id)
            ->select([
                'users_courses.course_id',
                'courses.title as course_title',
                'course_sections.id as cohort_id',
                'course_sections.name as cohort_name',
                'course_sections.start_date',
                'course_sections.end_date',
                'users_courses.created_at as enrolled_at',
            ])
            ->selectSub($scheduled, 'sessions_scheduled')
            ->selectSub($attended, 'sessions_attended')
            ->selectRaw(
                'CASE WHEN users_courses.course_id IN ('
                .($passedCourseIds === [] ? 'NULL' : implode(',', array_map('intval', $passedCourseIds)))
                .') THEN "completed" ELSE "active" END as status'
            )
            ->orderByDesc('users_courses.created_at')
            ->paginate($perPage);

        $this->attachCourseQualifications($page->getCollection());

        return $page;
    }

    /**
     * The qualifications each course on the page counts towards, in one query
     * for the whole page rather than one per row.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function attachCourseQualifications(\Illuminate\Support\Collection $rows): void
    {
        $courseIds = $rows->pluck('course_id')->unique()->values()->all();
        if ($courseIds === []) {
            return;
        }

        $byCourse = DB::table('course_qualification_skills as cqs')
            ->join('qualification_skills as qs', 'qs.id', '=', 'cqs.qualification_skill_id')
            ->whereIn('cqs.course_id', $courseIds)
            ->orderBy('qs.id')
            ->get(['cqs.course_id', 'qs.id', 'qs.name'])
            ->groupBy('course_id');

        foreach ($rows as $row) {
            $row->qualifications = ($byCourse->get($row->course_id) ?? collect())
                ->map(fn ($q) => ['id' => (int) $q->id, 'name' => LocalizedJson::pick($q->name)])
                ->values()
                ->all();
        }
    }

    /**
     * "Quizzes / Assignments Performance" table — quiz submissions and
     * assignment submissions in one list, newest first.
     *
     * The two live in different tables with different shapes, so they are
     * normalised into a common row via UNION ALL rather than merged in PHP,
     * which would break pagination.
     */
    public function performance(User $learner, int $perPage): LengthAwarePaginator
    {
        $quizzes = $this->inScope(DB::table('user_exams'), 'user_exams.course_id')
            ->join('course_exams', 'user_exams.exam_id', '=', 'course_exams.id')
            ->join('courses', 'user_exams.course_id', '=', 'courses.id')
            ->where('user_exams.user_id', $learner->id)
            ->selectRaw('"quiz" as kind')
            ->selectRaw('user_exams.id as id')
            ->selectRaw('course_exams.title as name')
            ->selectRaw('courses.title as course_title')
            // The quiz's own Pre / Mid / Post (D-065); older quizzes without one
            // keep the previous final-exam guess.
            ->selectRaw('COALESCE(course_exams.type, CASE WHEN course_exams.is_final = 1 THEN "post" ELSE "mid" END) as type')
            ->selectRaw('user_exams.user_degree as score')
            ->selectRaw('COALESCE(user_exams.max_score, user_exams.total_score) as max_score')
            ->selectRaw('user_exams.status as status')
            ->selectRaw('COALESCE(user_exams.submitted_at, user_exams.updated_at) as last_updated');

        $assignments = DB::table('user_course_assignments')
            ->join('course_assignments', 'user_course_assignments.course_assignment_id', '=', 'course_assignments.id')
            ->tap(fn ($q) => $this->inScope($q, 'course_assignments.course_id'))
            ->join('courses', 'course_assignments.course_id', '=', 'courses.id')
            ->where('user_course_assignments.user_id', $learner->id)
            ->selectRaw('"assignment" as kind')
            ->selectRaw('user_course_assignments.id as id')
            ->selectRaw('course_assignments.title as name')
            ->selectRaw('courses.title as course_title')
            ->selectRaw('"assignment" as type')
            ->selectRaw('user_course_assignments.score as score')
            ->selectRaw('COALESCE(user_course_assignments.max_score, user_course_assignments.total_score) as max_score')
            /*
             * An ungraded submission is "needs review" in the design, not a
             * fail. A graded one is pass/fail against the assignment's own
             * pass_score — deciding that here rather than in the resource,
             * because only the query has the threshold to hand. When an
             * assignment defines no pass_score, any grade counts as a pass.
             */
            ->selectRaw('CASE
                WHEN user_course_assignments.score IS NULL THEN "needs_review"
                WHEN course_assignments.pass_score IS NULL THEN "passed"
                WHEN user_course_assignments.score >= course_assignments.pass_score THEN "passed"
                ELSE "failed"
            END as status')
            ->selectRaw('COALESCE(user_course_assignments.submitted_at, user_course_assignments.updated_at) as last_updated');

        return DB::query()
            ->fromSub($quizzes->unionAll($assignments), 'perf')
            ->orderByDesc('perf.last_updated')
            ->paginate($perPage);
    }

    /**
     * Course scope (D-074): an account limited to its own courses sees this
     * learner's enrolments, quizzes and assignments in those courses only.
     */
    private function inScope(\Illuminate\Database\Query\Builder $q, string $courseColumn): \Illuminate\Database\Query\Builder
    {
        app(CourseScope::class)->constrain($q, request()->user(), $courseColumn);

        return $q;
    }
}
