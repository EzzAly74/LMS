<?php

namespace App\Services\Admin;

use App\Models\ReportExportLog;
use App\Support\CourseCompletion;
use App\Support\LearnerCompliance;
use App\Support\LocalizedJson;
use App\Support\PersonName;
use App\Support\QuizVerdict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service backing the 2026 Reports redesign.
 *
 * This service is intentionally NEW and additive — the existing
 * App\Http\Controllers\apis\ReportController and its routes
 * (routes/apis/reports.php) remain untouched.
 *
 * Responsibilities:
 *   - Provide the dataset for each of the 6 report cards.
 *   - Provide the live "Compliance by Job Title" preview table.
 *   - Track last-exported timestamps via report_export_logs.
 */
class AdminReportService
{
    /** Canonical list of report types exposed by the admin reports surface. */
    public const TYPES = [
        'compliance-by-job-title',
        'individual-compliance',
        'attendance',
        'completion',
        'scores',
        'certificate-status',
    ];

    public function __construct(private readonly CourseScope $scope) {}

    /* ------------------------------------------------------------------ *
     |  SUMMARY (cards)                                                 |
     * ------------------------------------------------------------------ */

    /**
     * Human-readable label + description per card.
     *
     * @return array<string,array{label:string,description:string,icon:string}>
     */
    public static function catalog(): array
    {
        return [
            'compliance-by-job-title' => [
                'label'       => 'Compliance by Job Title',
                'description' => 'Qualification completion % per job title across the organisation',
                'icon'        => 'shield-check',
            ],
            'individual-compliance' => [
                'label'       => 'Individual Compliance',
                'description' => "Each employee's qualification status: complete · in progress · not started",
                'icon'        => 'user',
            ],
            'attendance' => [
                'label'       => 'Attendance Report',
                'description' => 'Session attendance per course, cohort, and learner',
                'icon'        => 'calendar-check',
            ],
            'completion' => [
                'label'       => 'Completion Report',
                'description' => 'Course completion status per cohort and learner with dates',
                'icon'        => 'chart',
            ],
            'scores' => [
                'label'       => 'Scores Report',
                'description' => 'Quiz and assignment scores per learner and course',
                'icon'        => 'chart-bar',
            ],
            'certificate-status' => [
                'label'       => 'Certificate Status',
                'description' => 'All earned certificates with issue dates',
                'icon'        => 'badge',
            ],
        ];
    }

    /**
     * Cards payload for the Reports overview.
     *
     * @return array<int,array{
     *   key:string, label:string, description:string, icon:string,
     *   last_generated_at:string|null
     * }>
     */
    public function summary(): array
    {
        $lastByType = ReportExportLog::query()
            ->select('report_type', DB::raw('MAX(exported_at) AS last_exported_at'))
            ->groupBy('report_type')
            ->pluck('last_exported_at', 'report_type');

        $catalog = self::catalog();
        $cards   = [];

        foreach (self::TYPES as $type) {
            $meta = $catalog[$type];
            $cards[] = [
                'key'               => $type,
                'label'             => $meta['label'],
                'description'       => $meta['description'],
                'icon'              => $meta['icon'],
                'last_generated_at' => $lastByType[$type] ?? null,
            ];
        }

        return $cards;
    }

    /* ------------------------------------------------------------------ *
     |  COMPLIANCE PREVIEW (live table)                                   |
     * ------------------------------------------------------------------ */

    /**
     * Grouped compliance preview shown beneath the cards in Figma ("Compliance
     * by Job Title"; B-169, D-093).
     *
     * One row per job title: its learners, how many hold every qualification
     * the job title requires ("Qualified"), and the mean learner compliance
     * (the learners list's "Qualification (%)"). Department is the most
     * common department of the job title's learners, in the reader's
     * language. Learners without a job title are one "No job title" row.
     *
     * @return array<int,array{
     *   role:string,
     *   department:string|null,
     *   learners:int,
     *   qualified:int,
     *   compliance_pct:int
     * }>
     */
    public function compliancePreview(int $limit = 25): array
    {
        $locale = app()->getLocale();
        $learners = DB::table('users')
            ->leftJoin('job_titles', 'job_titles.id', '=', 'users.job_title_id')
            ->select('users.id', 'users.job_title_id', 'job_titles.name', 'job_titles.name_en', 'job_titles.name_ar',
                'users.department_name', 'users.department_name_en')
            ->tap(fn ($q) => $this->scope->constrainLearners($q, $this->principal(), 'users.id'))
            ->get();
        if ($learners->isEmpty()) {
            return [];
        }

        $compliance = LearnerCompliance::forUsers($learners->pluck('id')->all());

        return $learners
            ->groupBy(fn ($u) => (int) ($u->job_title_id ?? 0))
            ->map(function ($group) use ($compliance, $locale) {
                $first = $group->first();
                $title = $first->job_title_id === null
                    ? __('messages.reports.no_job_title')
                    : (($locale === 'ar' ? $first->name_ar : $first->name_en) ?: $first->name);
                $department = $group
                    ->map(fn ($u) => $locale === 'en' ? ($u->department_name_en ?: $u->department_name) : $u->department_name)
                    ->filter()->countBy()->sortDesc()->keys()->first();
                $values = $group->map(fn ($u) => $compliance[(int) $u->id]['compliance'] ?? null)->filter(fn ($v) => $v !== null);

                return [
                    'role'           => (string) $title,
                    'department'     => $department,
                    'learners'       => $group->count(),
                    'qualified'      => $group->filter(fn ($u) => $compliance[(int) $u->id]['qualified'] ?? false)->count(),
                    'compliance_pct' => $values->isEmpty() ? 0 : (int) round($values->avg()),
                ];
            })
            // Largest groups first; the name breaks ties so pages are stable.
            ->sortBy([['learners', 'desc'], ['role', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ *
     |  DATASETS                                                          |
     * ------------------------------------------------------------------ */

    /**
     * Build the full tabular dataset for a given report type. The first
     * element of the returned tuple is the column headings, the second is
     * the row data.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     *
     * @throws \InvalidArgumentException When the report type is unknown.
     */
    public function dataset(string $type): array
    {
        return match ($type) {
            'compliance-by-job-title' => $this->buildComplianceByJobTitle(),
            'individual-compliance'   => $this->buildIndividualCompliance(),
            'attendance'              => $this->buildAttendance(),
            'completion'              => $this->buildCompletion(),
            'scores'                  => $this->buildScores(),
            'certificate-status'      => $this->buildCertificateStatus(),
            default => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };
    }

    /* ------------------------------------------------------------------ *
     |  LOG                                                               |
     * ------------------------------------------------------------------ */

    public function logExport(string $type, string $format, ?int $adminId): void
    {
        ReportExportLog::query()->create([
            'report_type'          => $type,
            'format'               => $format,
            'exported_by_admin_id' => $adminId,
            'exported_at'          => Carbon::now(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     |  INTERNAL — Dataset builders                                       |
     * ------------------------------------------------------------------ */

    /**
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildComplianceByJobTitle(): array
    {
        $rows = $this->compliancePreview(limit: PHP_INT_MAX);

        return [
            ['Job Title', 'Department', 'Learners', 'Qualified', 'Compliance %'],
            array_map(static fn ($r) => [
                $r['role'],
                $r['department'] ?? '',
                $r['learners'],
                $r['qualified'],
                $r['compliance_pct'] . '%',
            ], $rows),
        ];
    }

    /**
     * One row per learner (B-169): enrolments, completed courses (the one
     * rule) and compliance (D-093). Status: Complete when every required
     * qualification is held, In progress once enrolled or partly compliant,
     * else Not started.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildIndividualCompliance(): array
    {
        $locale = app()->getLocale();
        $courseIds = $this->courseIds();
        $learners = DB::table('users')
            ->leftJoin('job_titles', 'job_titles.id', '=', 'users.job_title_id')
            ->tap(fn ($q) => $this->scope->constrainLearners($q, $this->principal(), 'users.id'))
            ->select('users.id', 'users.machine_code', DB::raw(PersonName::userSql('users').' AS learner'), 'users.email',
                'users.department_name', 'users.department_name_en', 'job_titles.name AS jt', 'job_titles.name_en AS jt_en', 'job_titles.name_ar AS jt_ar')
            ->orderBy('learner')
            ->get();
        $enrolled = DB::table('users_courses')
            ->whereIn('user_id', $learners->pluck('id')->all() ?: [0])
            ->when($courseIds !== null, fn ($q) => $q->whereIn('course_id', $courseIds ?: [0]))
            ->selectRaw('user_id, COUNT(*) AS c')->groupBy('user_id')->pluck('c', 'user_id');
        $compliance = LearnerCompliance::forUsers($learners->pluck('id')->all());

        return [
            ['Employee ID', 'Name', 'Email', 'Job Title', 'Department', 'Enrolled Courses', 'Completed Courses', 'Compliance %', 'Status'],
            $learners->map(function ($u) use ($enrolled, $compliance, $courseIds, $locale) {
                $c = $compliance[(int) $u->id] ?? ['completed' => [], 'compliance' => null, 'qualified' => false];
                $completed = $courseIds === null ? count($c['completed']) : count(array_intersect_key($c['completed'], array_flip($courseIds)));
                $enrolledCount = (int) ($enrolled[$u->id] ?? 0);
                $status = $c['qualified'] ? 'Complete'
                    : (($c['compliance'] ?? 0) > 0 || $enrolledCount > 0 ? 'In progress' : 'Not started');

                return [
                    $u->machine_code ?? '',
                    $u->learner ?? '',
                    $u->email ?? '',
                    ($locale === 'ar' ? $u->jt_ar : $u->jt_en) ?: ($u->jt ?? ''),
                    ($locale === 'en' ? ($u->department_name_en ?: $u->department_name) : $u->department_name) ?? '',
                    $enrolledCount,
                    $completed,
                    $c['compliance'] === null ? '' : $c['compliance'].'%',
                    $status,
                ];
            })->all(),
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildAttendance(): array
    {
        $rows = DB::table('attendances')
            ->leftJoin('users', 'users.id', '=', 'attendances.user_id')
            ->tap(fn ($q) => $this->scope->constrain($q, $this->principal(), 'attendances.course_id'))
            ->select(
                DB::raw(PersonName::userSql('users').' AS learner'),
                'users.email AS email',
                'attendances.course_name',
                'attendances.user_department',
                'attendances.attendance_hours',
                'attendances.course_hours',
                'attendances.created_at',
            )
            ->orderByDesc('attendances.created_at')
            ->get();

        return [
            ['Learner', 'Email', 'Course', 'Department', 'Attendance Hours', 'Course Hours', 'Date'],
            $rows->map(static fn ($r) => [
                $r->learner          ?? '',
                $r->email            ?? '',
                $r->course_name      ?? '',
                $r->user_department  ?? '',
                $r->attendance_hours ?? 0,
                $r->course_hours     ?? 0,
                $r->created_at       ? Carbon::parse($r->created_at)->format('Y-m-d') : '',
            ])->all(),
        ];
    }

    /**
     * Enrolments with their completion (B-169): completed by the one rule
     * (CourseCompletion) with its date, else in progress / not started by
     * the learner's module progress.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildCompletion(): array
    {
        $rows = DB::table('users_courses')
            ->leftJoin('users',   'users.id',   '=', 'users_courses.user_id')
            ->leftJoin('courses', 'courses.id', '=', 'users_courses.course_id')
            ->leftJoin('course_sections', 'course_sections.id', '=', 'users_courses.group_id')
            ->tap(fn ($q) => $this->scope->constrain($q, $this->principal(), 'users_courses.course_id'))
            ->select(
                'users_courses.user_id',
                DB::raw(PersonName::userSql('users').' AS learner'),
                'users.email AS email',
                'courses.id  AS course_id',
                'courses.title AS course_title',
                'course_sections.name AS cohort_name',
                'users_courses.created_at AS enrolled_at',
            )
            ->orderByDesc('users_courses.created_at')
            ->get();

        $completed = [];
        foreach (CourseCompletion::query($rows->pluck('user_id')->unique()->values()->all(), $rows->pluck('course_id')->unique()->values()->all())->get() as $pair) {
            $completed[(int) $pair->user_id.':'.(int) $pair->course_id] = $pair->completed_at ?? null;
        }
        $progress = app(\App\Services\LectureProgressService::class);
        $locale = app()->getLocale();

        return [
            ['Learner', 'Email', 'Course ID', 'Course', 'Cohort', 'Enrolled At', 'Status', 'Progress %', 'Completed At'],
            $rows->groupBy('user_id')->flatMap(function ($group, $userId) use ($completed, $progress, $locale) {
                $percent = $progress->getCourseProgressBatch((int) $userId, $group->pluck('course_id')->map(fn ($id) => (int) $id)->all());

                return $group->map(function ($r) use ($completed, $percent, $locale) {
                    $key = (int) $r->user_id.':'.(int) $r->course_id;
                    $done = array_key_exists($key, $completed);
                    $pct = (int) ($percent[(int) $r->course_id] ?? 0);

                    return [
                        $r->learner      ?? '',
                        $r->email        ?? '',
                        $r->course_id,
                        LocalizedJson::pick($r->course_title, $locale) ?? '',
                        LocalizedJson::pick($r->cohort_name, $locale) ?? '',
                        $r->enrolled_at  ? Carbon::parse($r->enrolled_at)->format('Y-m-d') : '',
                        $done ? 'Completed' : ($pct > 0 ? 'In progress' : 'Not started'),
                        $done ? 100 : $pct,
                        $done && $completed[$key] ? Carbon::parse($completed[$key])->format('Y-m-d') : '',
                    ];
                });
            })->values()->all(),
        ];
    }

    /**
     * Quiz and assignment results (B-169): sent attempts only; the score as
     * the Dashboard lists read it; Pass / Fail by QuizVerdict (D-087) and the
     * assignment pass score (D-090); "Needs review" while a person scores.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildScores(): array
    {
        $locale = app()->getLocale();
        $quizzes = DB::table('user_exams')
            ->tap(fn ($q) => $this->scope->constrain($q, $this->principal(), 'user_exams.course_id'))
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))
            ->leftJoin('users',        'users.id',        '=', 'user_exams.user_id')
            ->leftJoin('courses',      'courses.id',      '=', 'user_exams.course_id')
            ->join('course_exams', 'course_exams.id', '=', 'user_exams.exam_id')
            ->selectRaw('"Quiz" AS kind, '.PersonName::userSql('users').' AS learner, users.email, courses.title AS course_title, course_exams.title AS item_title')
            ->selectRaw(AdminQuizService::SCORE_SQL.' AS score, '.AdminQuizService::MAX_SQL.' AS max_score, course_exams.pass_score')
            ->selectRaw('COALESCE(user_exams.submitted_at, user_exams.created_at) AS at');
        $assignments = DB::table('user_course_assignments')
            ->join('course_assignments', 'course_assignments.id', '=', 'user_course_assignments.course_assignment_id')
            ->tap(fn ($q) => $this->scope->constrain($q, $this->principal(), 'course_assignments.course_id'))
            ->whereRaw(\App\Models\UserCourseAssignment::SENT_SQL)
            ->leftJoin('users',   'users.id',   '=', 'user_course_assignments.user_id')
            ->leftJoin('courses', 'courses.id', '=', 'course_assignments.course_id')
            ->selectRaw('"Assignment" AS kind, '.PersonName::userSql('users').' AS learner, users.email, courses.title AS course_title, course_assignments.title AS item_title')
            ->selectRaw(\App\Models\UserCourseAssignment::SCORE_SQL.' AS score, COALESCE(NULLIF(user_course_assignments.max_score, 0), course_assignments.total_score) AS max_score, '
                .\App\Models\CourseAssignment::PASS_SCORE_SQL.' AS pass_score')
            ->selectRaw('COALESCE(user_course_assignments.submitted_at, user_course_assignments.created_at) AS at');

        $rows = DB::query()->fromSub($quizzes->unionAll($assignments), 'r')->orderByDesc('r.at')->get();

        return [
            ['Learner', 'Email', 'Course', 'Item', 'Type', 'Score', 'Max', 'Result', 'Date'],
            $rows->map(static function ($r) use ($locale) {
                $result = $r->score === null ? 'Needs review'
                    : ($r->kind === 'Quiz'
                        ? (QuizVerdict::passes((float) $r->score, $r->pass_score !== null ? (float) $r->pass_score : null, (float) $r->max_score) ? 'Pass' : 'Fail')
                        : ((float) $r->score >= (float) $r->pass_score ? 'Pass' : 'Fail'));

                return [
                    $r->learner ?? '',
                    $r->email   ?? '',
                    LocalizedJson::pick($r->course_title, $locale) ?? '',
                    LocalizedJson::pick($r->item_title, $locale) ?? '',
                    $r->kind,
                    $r->score === null ? '' : (int) $r->score,
                    (int) $r->max_score,
                    $result,
                    $r->at ? Carbon::parse($r->at)->format('Y-m-d') : '',
                ];
            })->all(),
        ];
    }

    /**
     * Issued certificates (B-169): the `user_certificates` rows, active and
     * revoked, never enrolments.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    private function buildCertificateStatus(): array
    {
        $locale = app()->getLocale();
        $rows = DB::table('user_certificates')
            ->leftJoin('users',   'users.id',   '=', 'user_certificates.user_id')
            ->leftJoin('courses', 'courses.id', '=', 'user_certificates.course_id')
            ->tap(fn ($q) => $this->scope->constrain($q, $this->principal(), 'user_certificates.course_id'))
            ->select(
                DB::raw(PersonName::userSql('users').' AS learner'),
                'users.email   AS email',
                'courses.title AS course_title',
                'user_certificates.certificate_number',
                'user_certificates.status',
                'user_certificates.source_type',
                DB::raw('COALESCE(user_certificates.issued_at, user_certificates.created_at) AS issued_at'),
                'user_certificates.revoked_at',
            )
            ->orderByDesc('issued_at')
            ->get();

        return [
            ['Learner', 'Email', 'Course', 'Certificate No.', 'Status', 'Earned By', 'Issued At', 'Revoked At'],
            $rows->map(static fn ($r) => [
                $r->learner      ?? '',
                $r->email        ?? '',
                LocalizedJson::pick($r->course_title, $locale) ?? '',
                $r->certificate_number ?? '',
                $r->status === 'revoked' ? 'Revoked' : 'Active',
                ucfirst((string) $r->source_type),
                $r->issued_at  ? Carbon::parse($r->issued_at)->format('Y-m-d') : '',
                $r->revoked_at ? Carbon::parse($r->revoked_at)->format('Y-m-d') : '',
            ])->all(),
        ];
    }

    /* ------------------------------------------------------------------ *
     |  COURSE SCOPE (D-074)                                              |
     * ------------------------------------------------------------------ */

    /** The signed-in account; every dataset is limited to its courses when it is scoped. */
    private function principal(): mixed
    {
        return request()->user();
    }

    /** @return list<int>|null */
    private function courseIds(): ?array
    {
        return $this->scope->courseIds($this->principal());
    }
}
