<?php

namespace App\Services\Admin;

use App\Http\Traits\HasFile;
use App\Models\User;
use App\Services\Mobile\QualificationProgressService;
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
 * It is resolved: App\Support\CourseCompletion is the single definition
 * project-wide, the learner rule of D-082 (D-086). "Active" here is what the
 * Website lists under Current: enrolled, not finished (completed or cohort
 * ended), and not upcoming (first session still ahead).
 */
class AdminLearnerProfileService
{
    use HasFile;

    /** Profile card + the five summary tiles. */
    public function profile(User $learner): array
    {
        $states = $this->courseStates($learner);

        $completedCount = count(array_filter($states, fn (string $st) => $st === 'completed'));
        $activeIds      = array_keys(array_filter($states, fn (string $st) => $st === 'active'));

        // The latest submitted quiz: an opened attempt is not a result (B-162).
        $lastQuiz = $this->inScope(DB::table('user_exams'), 'user_exams.course_id')
            ->join('course_exams', 'course_exams.id', '=', 'user_exams.exam_id')
            ->where('user_exams.user_id', $learner->id)
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))
            ->orderByRaw('COALESCE(user_exams.submitted_at, user_exams.updated_at) DESC')
            ->orderByDesc('user_exams.id')
            ->first([
                'user_exams.user_degree',
                DB::raw(self::QUIZ_MAX_SQL.' AS max_score'),
                DB::raw(self::QUIZ_STATUS_SQL.' AS status'),
                DB::raw('COALESCE(user_exams.submitted_at, user_exams.updated_at) AS submitted_at'),
            ]);

        $lastActiveCourse = $this->lastActiveCourse($learner);

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
                // In the interface language (NEW2B-6227); HR's Arabic is the fallback.
                'department'  => app()->getLocale() === 'en' && filled($learner->department_name_en)
                    ? $learner->department_name_en
                    : $learner->department_name,
                // A URL the Dashboard can load, like every other resource. This
                // returned the raw disk path, which no <img> can resolve.
                // HR's photo when none was uploaded here (NEW2B-6219).
                'image_url'   => $learner->photoUrl(),
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
                'active_courses'    => count($activeIds),
                'active_course_progress_percent' => $this->activeCourseProgress($learner, $activeIds),
                'last_quiz' => $lastQuiz === null ? null : [
                    'score' => $lastQuiz->user_degree !== null ? (float) $lastQuiz->user_degree : null,
                    'max'   => (float) $lastQuiz->max_score,
                    'status' => $lastQuiz->status,
                    'submitted_at' => $lastQuiz->submitted_at,
                ],
                'earned_qualifications' => $earnedQualifications,
            ],
        ];
    }

    /**
     * Content-completion percentage across the learner's active courses (the
     * Website "Current" ones) — the "Active Course 88.3%" tile.
     *
     * Returns null rather than 0 when there is nothing in progress, so the UI
     * can render the design's "—" instead of an honest-looking zero.
     *
     * @param  list<int>  $activeCourseIds
     */
    private function activeCourseProgress(User $learner, array $activeCourseIds): ?float
    {
        if ($activeCourseIds === []) {
            return null;
        }

        // course_lectures carries course_id directly, so no join is needed.
        $totalLectures = DB::table('course_lectures')
            ->whereIn('course_id', $activeCourseIds)
            // The modules of the learner's cohort sheets (D-089).
            ->whereRaw(\App\Support\CohortModules::requiredSql((string) (int) $learner->id, 'course_lectures'))
            ->count();

        if ($totalLectures === 0) {
            return null;
        }

        $completedLectures = DB::table('user_lecture_progress')
            ->join('course_lectures', 'user_lecture_progress.lecture_id', '=', 'course_lectures.id')
            ->where('user_lecture_progress.user_id', $learner->id)
            ->where('user_lecture_progress.completed', true)
            ->whereIn('course_lectures.course_id', $activeCourseIds)
            ->whereRaw(\App\Support\CohortModules::requiredSql((string) (int) $learner->id, 'course_lectures'))
            ->count();

        return round($completedLectures * 100 / $totalLectures, 1);
    }

    /**
     * "Active & Completed Courses" table: course + cohort, attended, absent,
     * status, progress, started on, ended on.
     */
    public function courses(User $learner, int $perPage): LengthAwarePaginator
    {
        $states = $this->courseStates($learner);

        // Sessions scheduled for the learner's cohort on this course.
        $scheduled = DB::table('course_sessions')
            ->selectRaw('COUNT(*)')
            ->whereColumn('course_sessions.course_id', 'users_courses.course_id')
            ->whereColumn('course_sessions.section_id', 'users_courses.group_id');

        // Only sessions already over can be missed (P3-18): a future session
        // is not an absence.
        $past = DB::table('course_sessions')
            ->selectRaw('COUNT(*)')
            ->whereColumn('course_sessions.course_id', 'users_courses.course_id')
            ->whereColumn('course_sessions.section_id', 'users_courses.group_id')
            ->whereRaw("TIMESTAMP(course_sessions.session_date, COALESCE(course_sessions.time_to, '23:59:59')) < ?", [now()->format('Y-m-d H:i:s')]);

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
            ->selectSub($past, 'sessions_past')
            ->selectSub($attended, 'sessions_attended')
            ->orderByDesc('users_courses.created_at')
            ->paginate($perPage);

        // completed | active | upcoming | ended, as the Website buckets them.
        foreach ($page->getCollection() as $row) {
            $row->status = $states[(int) $row->course_id] ?? 'active';
        }

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
            // An opened, unsubmitted attempt is not a result (B-162).
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))
            ->selectRaw('"quiz" as kind')
            ->selectRaw('user_exams.id as id')
            ->selectRaw('course_exams.title as name')
            ->selectRaw('courses.title as course_title')
            // The quiz's own Pre / Mid / Post (D-065); older quizzes without one
            // keep the previous final-exam guess.
            ->selectRaw('COALESCE(course_exams.type, CASE WHEN course_exams.is_final = 1 THEN "post" ELSE "mid" END) as type')
            ->selectRaw('user_exams.user_degree as score')
            ->selectRaw(self::QUIZ_MAX_SQL.' as max_score')
            ->selectRaw(self::QUIZ_STATUS_SQL.' as status')
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
             * because only the query has the threshold to hand. Without a
             * pass_score the pass mark is half the total (D-090).
             */
            ->selectRaw('CASE
                WHEN user_course_assignments.score IS NULL THEN "needs_review"
                WHEN user_course_assignments.score >= '.\App\Models\CourseAssignment::PASS_SCORE_SQL.' THEN "passed"
                ELSE "failed"
            END as status')
            ->selectRaw('COALESCE(user_course_assignments.submitted_at, user_course_assignments.updated_at) as last_updated');

        return DB::query()
            ->fromSub($quizzes->unionAll($assignments), 'perf')
            ->orderByDesc('perf.last_updated')
            ->paginate($perPage);
    }

    /**
     * The quiz's full mark: the attempt's stored maximum, else the quiz's total
     * (rich quizzes) or degree (legacy exams). Never 0 for a legacy exam.
     */
    private const QUIZ_MAX_SQL = 'COALESCE(NULLIF(user_exams.max_score, 0), NULLIF(course_exams.total_score, 0), course_exams.degree, 0)';

    /**
     * Pass / fail from the score itself (NEW2B-6232), not the stored `status`
     * column, which older rows default to 'success' and grading never updated:
     * the quiz's pass score when it has one, else half the full mark (the
     * rule the legacy exams always used, D-087). An attempt with open
     * questions still to grade is "needs review" (no score yet).
     */
    private const QUIZ_STATUS_SQL = "CASE
        WHEN EXISTS (
            SELECT 1 FROM user_exam_answers uea
            JOIN course_exam_questions ceq ON ceq.id = uea.question_id
            WHERE uea.user_exam_id = user_exams.id AND ceq.type = 'open' AND uea.awarded_score IS NULL
        ) THEN 'needs_review'
        WHEN course_exams.pass_score IS NOT NULL AND user_exams.user_degree >= course_exams.pass_score THEN 'passed'
        WHEN course_exams.pass_score IS NOT NULL THEN 'failed'
        WHEN user_exams.user_degree * 2 >= COALESCE(NULLIF(user_exams.max_score, 0), NULLIF(course_exams.total_score, 0), course_exams.degree, 0) THEN 'passed'
        ELSE 'failed'
    END";

    /**
     * Each in-scope enrolment's state, as the Website buckets it (D-080,
     * D-081, D-086): completed (CourseCompletion), ended (cohort over without a
     * completion), upcoming (first session still ahead), else active.
     *
     * @return array<int, string>  course id => completed|ended|upcoming|active
     */
    private function courseStates(User $learner): array
    {
        $enrolments = $this->inScope(DB::table('users_courses'), 'users_courses.course_id')
            ->where('users_courses.user_id', $learner->id)
            ->get(['users_courses.course_id', 'users_courses.group_id']);

        if ($enrolments->isEmpty()) {
            return [];
        }

        $completed = array_flip(CourseCompletion::courseIdsFor($learner->id)->all());
        $finished  = array_flip(app(QualificationProgressService::class)
            ->finishedCourseIdsForUser((int) $learner->id)
            ->map(fn ($id) => (int) $id)
            ->all());

        // First session start per cohort (the Website's Upcoming flag).
        $firstStarts = DB::table('course_sessions')
            ->whereIn('section_id', $enrolments->pluck('group_id')->filter()->all() ?: [0])
            ->groupBy('section_id')
            ->selectRaw("section_id, MIN(TIMESTAMP(session_date, COALESCE(time_from, '00:00:00'))) AS first_start")
            ->pluck('first_start', 'section_id');

        $now    = now();
        $states = [];
        foreach ($enrolments as $e) {
            $courseId = (int) $e->course_id;
            $first    = $e->group_id !== null ? ($firstStarts[$e->group_id] ?? null) : null;

            $states[$courseId] = match (true) {
                isset($completed[$courseId])                              => 'completed',
                isset($finished[$courseId])                               => 'ended',
                $first !== null && \Illuminate\Support\Carbon::parse($first)->greaterThan($now) => 'upcoming',
                default                                                   => 'active',
            };
        }

        return $states;
    }

    /**
     * The course the learner last worked on: the latest content progress,
     * quiz submission or attendance (P1-5); before any activity, the latest
     * enrolment. The enrolment row's updated_at moves on any admin edit, so it
     * is not used.
     */
    private function lastActiveCourse(User $learner): ?object
    {
        $content = DB::table('user_lecture_progress')
            ->join('course_lectures', 'course_lectures.id', '=', 'user_lecture_progress.lecture_id')
            ->where('user_lecture_progress.user_id', $learner->id)
            ->selectRaw('course_lectures.course_id AS course_id, user_lecture_progress.updated_at AS at');
        $quizzes = DB::table('user_exams')
            ->where('user_id', $learner->id)
            ->whereNotNull('course_id')
            ->selectRaw('course_id, COALESCE(submitted_at, updated_at) AS at');
        $attendance = DB::table('attendances')
            ->where('user_id', $learner->id)
            ->selectRaw('course_id, created_at AS at');

        $latest = $this->inScope(
            DB::query()->fromSub($content->unionAll($quizzes)->unionAll($attendance), 'act'),
            'act.course_id',
        )
            ->whereNotNull('act.at')
            ->orderByDesc('act.at')
            ->first(['act.course_id']);

        $courseId = $latest?->course_id
            ?? $this->inScope(DB::table('users_courses'), 'users_courses.course_id')
                ->where('users_courses.user_id', $learner->id)
                ->orderByDesc('users_courses.created_at')
                ->orderByDesc('users_courses.id')
                ->value('users_courses.course_id');

        return $courseId === null
            ? null
            : DB::table('courses')->where('id', $courseId)->first(['id', 'title']);
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
