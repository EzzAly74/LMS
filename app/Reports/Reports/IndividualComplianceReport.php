<?php

namespace App\Reports\Reports;

use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\QualificationStanding;
use App\Reports\Support\Scope;
use App\Support\CourseQualifications;
use App\Support\LocalizedJson;
use App\Support\QualificationHolding;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 2. Individual Compliance: one row per employee x qualification (D-112).
 *
 * The qualifications are the employee's job title's, plus any granted to
 * them directly (an approved external training, D-045). Complete holds it
 * (QualificationHolding), In Progress has enrolled in or completed a linked
 * course, Not Started neither. Assigned Date is when the qualification was
 * added to the job title or granted, whichever came first; Completion Date
 * the grant, or the last linked course completed. Qualifications have no
 * code, due date or expiry (human 2026-10-18: no expiry), so Code, Due Date
 * and Overdue are not captured, and Expiry Date is dropped.
 */
final class IndividualComplianceReport extends QueryReport
{
    public const STATUSES = [
        'complete' => 'reports.values.qualification.complete',
        'in_progress' => 'reports.values.qualification.in_progress',
        'not_started' => 'reports.values.qualification.not_started',
    ];

    public function key(): string
    {
        return 'individual-compliance';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('qualification_name', 'qualification'),
            Column::missing('qualification_code', 'qualification'),
            new Column('required_courses', 'progress', Column::NUMBER),
            new Column('completed_courses', 'progress', Column::NUMBER),
            new Column('completion_pct', 'progress', Column::PERCENT),
            new Column('qualification_status', 'progress'),
            new Column('assigned_date', 'dates', Column::DATE),
            Column::missing('due_date', 'dates', Column::DATE),
            new Column('completion_date', 'dates', Column::DATE),
            Column::missing('overdue', 'dates', Column::YES_NO),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('departments', 'departments'),
            Filter::multi('job_title_ids', 'job_titles'),
            Filter::multi('employee_ids', 'employees'),
            Filter::multi('qualification_ids', 'qualifications'),
            Filter::status('statuses', self::STATUSES),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $pairs = DB::table('users as pu')
            ->join('job_title_qualification_skill as pj', 'pj.job_title_id', '=', 'pu.job_title_id')
            ->select('pu.id as user_id', 'pj.qualification_skill_id')
            ->union(DB::table('user_qualification_skill')->select('user_id', 'qualification_skill_id'));

        $query = DB::query()->fromSub($pairs, 'p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('qualification_skills as q', 'q.id', '=', 'p.qualification_skill_id')
            ->select(['p.user_id', 'p.qualification_skill_id', 'q.name as qualification_name'])
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::learners($q, $context, 'u.id'))
            ->when($context->ids('qualification_ids'), fn ($q, $v) => $q->whereIn('p.qualification_skill_id', $v));

        $statuses = $context->strings('statuses');
        if ($statuses !== [] && count($statuses) < count(self::STATUSES)) {
            $query->whereRaw('('.self::statusSql('p.user_id', 'p.qualification_skill_id').') IN ('
                .implode(',', array_fill(0, count($statuses), '?')).')', $statuses);
        }

        return $query
            ->orderByRaw('COALESCE(NULLIF(u.name_en, \'\'), NULLIF(u.name_ar, \'\'), u.name)')
            ->orderBy('u.id')
            ->orderBy('p.qualification_skill_id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $standing = QualificationStanding::forUsers($rows->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($standing, $context, $keys) {
            $s = $standing[(int) $row->user_id][(int) $row->qualification_skill_id] ?? null;
            $status = $s === null ? null : ($s['holds'] ? 'complete' : ($s['started'] ? 'in_progress' : 'not_started'));

            return self::shape([
                ...Employee::values($row, $context),
                'qualification_name' => LocalizedJson::pick($row->qualification_name, $context->locale),
                'required_courses' => $s['linked'] ?? null,
                'completed_courses' => $s['passed'] ?? null,
                'completion_pct' => $s === null ? null : (float) $s['percent'],
                'qualification_status' => $status === null ? null : __(self::STATUSES[$status]),
                'assigned_date' => Employee::date($s['assigned_at'] ?? null),
                'completion_date' => Employee::date($s['completed_at'] ?? null),
            ], $keys);
        })->values()->all();
    }

    /**
     * The status in SQL, mirroring QualificationStanding: complete when held,
     * in progress once a linked course is completed or enrolled in.
     */
    private static function statusSql(string $user, string $qualification): string
    {
        $holds = QualificationHolding::holdsSql($user, $qualification);
        $enrolled = CourseQualifications::countSql($user, $qualification,
            "EXISTS (SELECT 1 FROM users_courses ic_e WHERE ic_e.user_id = {$user} AND ic_e.course_id = {course})");

        $passed = QualificationHolding::passedCoursesSql($user, $qualification);

        return "CASE WHEN {$holds} THEN 'complete' WHEN {$passed} > 0 OR {$enrolled} > 0 THEN 'in_progress' ELSE 'not_started' END";
    }
}
