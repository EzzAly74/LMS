<?php

namespace App\Reports\Reports;

use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Services\Admin\AdminQuizService;
use App\Services\LectureProgressService;
use App\Support\CourseCompletion;
use App\Support\LocalizedJson;
use App\Support\QuizVerdict;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 4. Completion: one row per learner x course (D-112).
 *
 *   Enrollment Date   the enrolment row's creation
 *   Start Date        the learner's first activity in the course: a module
 *                     opened, a session attended, a quiz opened or an
 *                     assignment sent (no "first opened" moment is stored)
 *   Completion Date   the certificate's issue date (CourseCompletion, D-094)
 *   Status            Completed (an active certificate), Failed (the cohort
 *                     has ended without it, Course::deriveCohortStatus),
 *                     In Progress (started), Not Started. Nothing withdraws
 *                     an enrolment, so Withdrawn never occurs.
 *   Progress %        LectureProgressService, as the learner sees it
 *   Final Score       the final quiz's score %; Result is its pass / fail
 *                     (QuizVerdict, D-087)
 *
 * Due Date and Time Spent are not captured.
 */
final class CompletionReport extends QueryReport
{
    public const STATUSES = [
        'completed' => 'reports.values.completion.completed',
        'in_progress' => 'reports.values.completion.in_progress',
        'not_started' => 'reports.values.completion.not_started',
        'failed' => 'reports.values.completion.failed',
    ];

    public function key(): string
    {
        return 'completion';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('course_name', 'course'),
            Column::missing('course_code', 'course'),
            new Column('category', 'course'),
            new Column('cohort', 'course'),
            new Column('enrollment_date', 'dates', Column::DATE),
            new Column('start_date', 'dates', Column::DATE),
            Column::missing('due_date', 'dates', Column::DATE),
            new Column('completion_date', 'dates', Column::DATE),
            new Column('completion_status', 'progress'),
            new Column('progress_pct', 'progress', Column::PERCENT),
            Column::missing('time_spent', 'progress', Column::NUMBER),
            new Column('final_score', 'result', Column::PERCENT),
            new Column('result', 'result'),
            new Column('certificate_issued', 'result', Column::YES_NO),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('course_ids', 'courses'),
            Filter::multi('category_ids', 'categories'),
            Filter::multi('cohort_ids', 'cohorts', dependsOn: 'course_ids'),
            Filter::status('statuses', self::STATUSES),
            Filter::dateRange('enrolled'),
            Filter::dateRange('completed'),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $completed = CourseCompletion::existsSql('uc.user_id', 'uc.course_id');
        $query = DB::table('users_courses as uc')
            ->join('users as u', 'u.id', '=', 'uc.user_id')
            ->join('courses as c', 'c.id', '=', 'uc.course_id')
            ->leftJoin('course_sections as cs', 'cs.id', '=', 'uc.group_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'c.category_id')
            ->select([
                'uc.user_id', 'uc.course_id', 'uc.group_id', 'uc.created_at as enrolled_at',
                'c.title as course_title', 'cs.name as cohort_name', 'cat.name as category_name',
            ])
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::courses($q, $context, 'uc.course_id'))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('uc.course_id', $v))
            ->when($context->ids('category_ids'), fn ($q, $v) => $q->whereIn('c.category_id', $v))
            ->when($context->ids('cohort_ids'), fn ($q, $v) => $q->whereIn('uc.group_id', $v))
            ->when($context->from('enrolled'), fn ($q, $d) => $q->where('uc.created_at', '>=', $d))
            ->when($context->to('enrolled'), fn ($q, $d) => $q->where('uc.created_at', '<=', $d));

        $completedFrom = $context->from('completed');
        $completedTo = $context->to('completed');
        if ($completedFrom || $completedTo) {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('user_certificates as fc')
                ->whereColumn('fc.user_id', 'uc.user_id')->whereColumn('fc.course_id', 'uc.course_id')
                ->where('fc.status', 'active')
                ->when($completedFrom, fn ($w) => $w->whereRaw('COALESCE(fc.issued_at, fc.created_at) >= ?', [$completedFrom]))
                ->when($completedTo, fn ($w) => $w->whereRaw('COALESCE(fc.issued_at, fc.created_at) <= ?', [$completedTo])));
        }

        $statuses = $context->strings('statuses');
        if ($statuses !== [] && count($statuses) < count(self::STATUSES)) {
            $query->whereRaw('('.self::statusSql($completed).') IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses);
        }

        return $query
            ->selectRaw("{$completed} AS is_completed")
            ->selectRaw(self::endedSql().' AS cohort_ended')
            ->orderByDesc('uc.created_at')
            ->orderByDesc('uc.id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $userIds = $rows->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $courseIds = $rows->pluck('course_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $completedAt = [];
        foreach (CourseCompletion::query($userIds, $courseIds)->get() as $pair) {
            $completedAt[$pair->user_id.':'.$pair->course_id] = $pair->completed_at;
        }
        $startedAt = self::firstActivity($userIds, $courseIds);
        $finals = self::finalScores($userIds, $courseIds);
        $progress = [];
        $service = app(LectureProgressService::class);
        foreach ($rows->groupBy('user_id') as $userId => $group) {
            foreach ($service->getCourseProgressBatch((int) $userId, $group->pluck('course_id')->map(fn ($v) => (int) $v)->all()) as $courseId => $pct) {
                $progress[$userId.':'.$courseId] = $pct;
            }
        }
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($completedAt, $startedAt, $finals, $progress, $context, $keys) {
            $pair = $row->user_id.':'.$row->course_id;
            $done = (bool) $row->is_completed;
            $started = $startedAt[$pair] ?? null;
            $status = match (true) {
                $done => 'completed',
                (bool) $row->cohort_ended => 'failed',
                $started !== null => 'in_progress',
                default => 'not_started',
            };
            $final = $finals[$pair] ?? null;

            return self::shape([
                ...Employee::values($row, $context),
                'course_name' => LocalizedJson::pick($row->course_title, $context->locale),
                'category' => LocalizedJson::pick($row->category_name, $context->locale),
                'cohort' => LocalizedJson::pick($row->cohort_name, $context->locale),
                'enrollment_date' => Employee::date($row->enrolled_at),
                'start_date' => Employee::date($started),
                'completion_date' => $done ? Employee::date($completedAt[$pair] ?? null) : null,
                'completion_status' => __(self::STATUSES[$status]),
                'progress_pct' => $done ? 100.0 : (float) ($progress[$pair] ?? 0),
                'final_score' => $final['pct'] ?? null,
                'result' => $final['result'] ?? null,
                'certificate_issued' => $done,
            ], $keys);
        })->values()->all();
    }

    /** The status in SQL, mirroring map(). */
    private static function statusSql(string $completed): string
    {
        return "CASE WHEN {$completed} THEN 'completed' WHEN ".self::endedSql()." THEN 'failed' WHEN ".self::startedSql()
            ." THEN 'in_progress' ELSE 'not_started' END";
    }

    /**
     * The learner's cohort has ended (Course::deriveCohortStatus = completed):
     * every planned session held when it has a session target, else past its
     * end date. An inactive cohort or one not started yet has not ended.
     */
    private static function endedSql(): string
    {
        $today = now()->toDateString();
        $target = 'COALESCE(NULLIF((SELECT COUNT(*) FROM course_sessions es WHERE es.section_id = cs.id), 0), cs.number_of_sessions)';
        $held = "(SELECT COUNT(*) FROM course_sessions hs WHERE hs.section_id = cs.id AND (hs.session_date < '{$today}'"
            ." OR (hs.session_date = '{$today}' AND hs.time_to IS NOT NULL AND hs.time_to <= '".now()->format('H:i:s')."')))";

        return "(cs.id IS NOT NULL AND COALESCE(cs.status, '') <> 'inactive'"
            ." AND (cs.start_date IS NULL OR cs.start_date <= '{$today}')"
            ." AND (CASE WHEN {$target} > 0 THEN {$held} >= {$target} ELSE cs.end_date IS NOT NULL AND cs.end_date < '{$today}' END))";
    }

    /** Any activity of the learner in the course. */
    private static function startedSql(): string
    {
        return '(EXISTS (SELECT 1 FROM user_lecture_progress sp JOIN course_lectures sl ON sl.id = sp.lecture_id'
            .' WHERE sp.user_id = uc.user_id AND sl.course_id = uc.course_id)'
            .' OR EXISTS (SELECT 1 FROM attendances sa WHERE sa.user_id = uc.user_id AND sa.course_id = uc.course_id)'
            .' OR EXISTS (SELECT 1 FROM user_exams se WHERE se.user_id = uc.user_id AND se.course_id = uc.course_id)'
            .' OR EXISTS (SELECT 1 FROM user_course_assignments sx JOIN course_assignments sxa ON sxa.id = sx.course_assignment_id'
            .' WHERE sx.user_id = uc.user_id AND sxa.course_id = uc.course_id))';
    }

    /**
     * The first activity moment per "user:course" (see startedSql()).
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $courseIds
     * @return array<string, string>
     */
    private static function firstActivity(array $userIds, array $courseIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $sources = [
            DB::table('user_lecture_progress as p')->join('course_lectures as l', 'l.id', '=', 'p.lecture_id')
                ->whereIn('p.user_id', $userIds)->whereIn('l.course_id', $courseIds)
                ->groupBy('p.user_id', 'l.course_id')->selectRaw('p.user_id, l.course_id, MIN(p.created_at) AS at'),
            DB::table('attendances')->whereIn('user_id', $userIds)->whereIn('course_id', $courseIds)
                ->groupBy('user_id', 'course_id')->selectRaw('user_id, course_id, MIN(created_at) AS at'),
            DB::table('user_exams')->whereIn('user_id', $userIds)->whereIn('course_id', $courseIds)
                ->groupBy('user_id', 'course_id')->selectRaw('user_id, course_id, MIN(created_at) AS at'),
            DB::table('user_course_assignments as s')->join('course_assignments as a', 'a.id', '=', 's.course_assignment_id')
                ->whereIn('s.user_id', $userIds)->whereIn('a.course_id', $courseIds)
                ->groupBy('s.user_id', 'a.course_id')->selectRaw('s.user_id, a.course_id, MIN(COALESCE(s.submitted_at, s.created_at)) AS at'),
        ];

        $first = [];
        foreach ($sources as $source) {
            foreach ($source->get() as $row) {
                $key = $row->user_id.':'.$row->course_id;
                if ($row->at !== null && (! isset($first[$key]) || $row->at < $first[$key])) {
                    $first[$key] = $row->at;
                }
            }
        }

        return $first;
    }

    /**
     * The submitted final quiz per "user:course": its score % and pass / fail
     * (null while a person still scores it).
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $courseIds
     * @return array<string, array{pct: ?float, result: ?string}>
     */
    private static function finalScores(array $userIds, array $courseIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('user_exams')
            ->join('course_exams', 'course_exams.id', '=', 'user_exams.exam_id')
            ->where('course_exams.is_final', true)
            ->whereIn('user_exams.user_id', $userIds)
            ->whereIn('user_exams.course_id', $courseIds)
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))
            ->orderBy('user_exams.id')
            ->get([
                'user_exams.user_id', 'user_exams.course_id', 'course_exams.pass_score',
                DB::raw(AdminQuizService::SCORE_SQL.' AS score'), DB::raw(AdminQuizService::MAX_SQL.' AS max_score'),
            ]) as $row) {
            $max = (float) $row->max_score;
            $out[$row->user_id.':'.$row->course_id] = $row->score === null || $max <= 0
                ? ['pct' => null, 'result' => null]
                : [
                    'pct' => self::percent((float) $row->score, $max),
                    'result' => __(QuizVerdict::passes((float) $row->score, $row->pass_score !== null ? (float) $row->pass_score : null, $max)
                        ? 'reports.values.result.pass' : 'reports.values.result.fail'),
                ];
        }

        return $out;
    }
}
