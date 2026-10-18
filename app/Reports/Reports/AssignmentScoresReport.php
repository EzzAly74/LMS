<?php

namespace App\Reports\Reports;

use App\Models\CourseAssignment;
use App\Models\UserCourseAssignment;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Support\LocalizedJson;
use App\Support\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 6. Assignment Scores: one row per learner x assignment sent (D-112).
 *
 * Grade as the Dashboard reads it (UserCourseAssignment::SCORE_SQL), Max the
 * submission's full mark else the assignment's, Pass Mark the assignment's
 * pass score (half the mark when unset, CourseAssignment::PASS_SCORE_SQL,
 * D-090) as a %. Pending Grading while a person still scores an answer.
 * Late: sent after the due day. Graded By / Graded Date are the reviewer and
 * review moment. A learner sends an assignment once (one row per learner and
 * assignment), so Resubmissions are not captured.
 */
final class AssignmentScoresReport extends QueryReport
{
    public const RESULTS = [
        'pass' => 'reports.values.result.pass',
        'fail' => 'reports.values.result.fail',
        'pending' => 'reports.values.result.pending_grading',
    ];

    private const MAX_SQL = 'COALESCE(NULLIF(user_course_assignments.max_score, 0), course_assignments.total_score)';

    public function key(): string
    {
        return 'assignment-scores';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('course_name', 'course'),
            new Column('assignment_name', 'assignment'),
            new Column('due_date', 'dates', Column::DATE),
            new Column('submission_date', 'dates', Column::DATETIME),
            new Column('late', 'dates', Column::YES_NO),
            new Column('grade', 'grade', Column::NUMBER),
            new Column('max_grade', 'grade', Column::NUMBER),
            new Column('grade_pct', 'grade', Column::PERCENT),
            new Column('pass_mark', 'grade', Column::PERCENT),
            new Column('result', 'grade'),
            new Column('graded_by', 'grading'),
            new Column('graded_date', 'grading', Column::DATE),
            Column::missing('resubmissions', 'grading', Column::NUMBER),
            new Column('feedback', 'grading'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('course_ids', 'courses'),
            Filter::multi('assignment_ids', 'assignments', dependsOn: 'course_ids'),
            Filter::multi('cohort_ids', 'cohorts', dependsOn: 'course_ids'),
            Filter::dateRange('due'),
            Filter::status('results', self::RESULTS),
            Filter::multi('grader_ids', 'graders'),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $score = UserCourseAssignment::SCORE_SQL;
        $sentAt = 'COALESCE(user_course_assignments.submitted_at, user_course_assignments.created_at)';
        $query = DB::table('user_course_assignments')
            ->join('course_assignments', 'course_assignments.id', '=', 'user_course_assignments.course_assignment_id')
            ->join('users as u', 'u.id', '=', 'user_course_assignments.user_id')
            ->leftJoin('courses as c', 'c.id', '=', 'course_assignments.course_id')
            ->leftJoin('admins as g', 'g.id', '=', 'user_course_assignments.reviewed_by')
            ->whereRaw(UserCourseAssignment::SENT_SQL)
            ->select([
                'course_assignments.title as assignment_title', 'course_assignments.title_ar as assignment_title_ar',
                'course_assignments.due_date', 'user_course_assignments.reviewed_at',
                'user_course_assignments.feedback', 'c.title as course_title',
            ])
            ->selectRaw("{$score} AS grade")
            ->selectRaw(self::MAX_SQL.' AS max_grade')
            ->selectRaw(CourseAssignment::PASS_SCORE_SQL.' AS pass_score')
            ->selectRaw("{$sentAt} AS sent_at")
            ->selectRaw(PersonName::adminSql('g', $context->locale).' AS grader_name')
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::courses($q, $context, 'course_assignments.course_id'))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('course_assignments.course_id', $v))
            ->when($context->ids('assignment_ids'), fn ($q, $v) => $q->whereIn('course_assignments.id', $v))
            ->when($context->ids('grader_ids'), fn ($q, $v) => $q->whereIn('user_course_assignments.reviewed_by', $v))
            ->when($context->ids('cohort_ids'), fn ($q, $v) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('users_courses as ac')
                ->whereColumn('ac.user_id', 'user_course_assignments.user_id')->whereColumn('ac.course_id', 'course_assignments.course_id')
                ->whereIn('ac.group_id', $v)))
            ->when($context->from('due'), fn ($q, $d) => $q->whereDate('course_assignments.due_date', '>=', $d->toDateString()))
            ->when($context->to('due'), fn ($q, $d) => $q->whereDate('course_assignments.due_date', '<=', $d->toDateString()));

        $results = $context->strings('results');
        if ($results !== [] && count($results) < count(self::RESULTS)) {
            $passes = "{$score} >= ".CourseAssignment::PASS_SCORE_SQL;
            $query->where(function (Builder $w) use ($results, $score, $passes) {
                foreach ($results as $result) {
                    match ($result) {
                        'pending' => $w->orWhereRaw("{$score} IS NULL"),
                        'pass' => $w->orWhereRaw("({$score} IS NOT NULL AND {$passes})"),
                        'fail' => $w->orWhereRaw("({$score} IS NOT NULL AND NOT ({$passes}))"),
                    };
                }
            });
        }

        return $query->orderByRaw("{$sentAt} DESC")->orderByDesc('user_course_assignments.id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($context, $keys) {
            $max = $row->max_grade === null ? null : (float) $row->max_grade;
            $grade = $row->grade === null ? null : (float) $row->grade;
            $pass = $row->pass_score === null ? null : (float) $row->pass_score;
            $result = $grade === null ? 'pending' : ($pass !== null && $grade >= $pass ? 'pass' : 'fail');
            $title = $context->isArabic() && Employee::text($row->assignment_title_ar)
                ? $row->assignment_title_ar
                : LocalizedJson::pick($row->assignment_title, $context->locale);

            return self::shape([
                ...Employee::values($row, $context),
                'course_name' => LocalizedJson::pick($row->course_title, $context->locale),
                'assignment_name' => Employee::text($title) ?? Employee::text($row->assignment_title_ar),
                'due_date' => Employee::date($row->due_date),
                'submission_date' => Employee::dateTime($row->sent_at),
                'late' => $row->due_date && $row->sent_at ? substr((string) $row->sent_at, 0, 10) > Employee::date($row->due_date) : null,
                'grade' => $grade,
                'max_grade' => $max,
                'grade_pct' => $grade === null ? null : self::percent($grade, $max),
                'pass_mark' => $pass === null ? null : self::percent($pass, $max),
                'result' => __(self::RESULTS[$result]),
                'graded_by' => $grade === null ? null : Employee::text($row->grader_name),
                'graded_date' => $grade === null ? null : Employee::date($row->reviewed_at),
                'feedback' => Employee::text($row->feedback),
            ], $keys);
        })->values()->all();
    }
}
