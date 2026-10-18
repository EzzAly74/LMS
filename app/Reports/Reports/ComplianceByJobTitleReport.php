<?php

namespace App\Reports\Reports;

use App\Models\Setting;
use App\Reports\Column;
use App\Reports\ComputedReport;
use App\Reports\Filter;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\QualificationStanding;
use App\Reports\Support\Scope;
use Illuminate\Support\Facades\DB;

/**
 * 1. Compliance by Job Title: one row per job title (NAS spec, D-112).
 *
 * Over the title's active employees: Fully Compliant holds every required
 * qualification, In Progress started at least one (a linked course enrolled
 * in or completed) without holding them all, Not Started none. Compliance %
 * = Fully Compliant / Total Employees; Status compares it with the
 * organisation-wide target (Platform Config `compliance_target`, 90% by
 * default; human 2026-10-18). Job titles have no code and no due dates, so
 * Job Title Code and Overdue are not captured. Department is the most common
 * department of the title's employees (HR keeps no department per title).
 */
final class ComplianceByJobTitleReport extends ComputedReport
{
    public const DEFAULT_TARGET = 90;

    public function key(): string
    {
        return 'compliance-by-job-title';
    }

    public function columns(ReportContext $context): array
    {
        return [
            new Column('job_title', 'job_title'),
            Column::missing('job_title_code', 'job_title'),
            new Column('department', 'job_title'),
            new Column('total_employees', 'headcount', Column::NUMBER),
            new Column('required_qualifications', 'requirements', Column::NUMBER),
            new Column('fully_compliant', 'progress', Column::NUMBER),
            new Column('in_progress', 'progress', Column::NUMBER),
            new Column('not_started', 'progress', Column::NUMBER),
            Column::missing('overdue', 'progress', Column::NUMBER),
            new Column('compliance_pct', 'progress', Column::PERCENT),
            new Column('target_pct', 'target', Column::PERCENT),
            new Column('compliance_status', 'target'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('departments', 'departments'),
            Filter::multi('job_title_ids', 'job_titles'),
            Filter::date('as_of'),
        ];
    }

    protected function build(ReportContext $context): array
    {
        $employees = DB::table('users as u')
            ->join('job_titles as jt', 'jt.id', '=', 'u.job_title_id')
            ->tap(fn ($q) => Scope::activeEmployees($q))
            ->tap(fn ($q) => Scope::learners($q, $context, 'u.id'))
            ->when($context->strings('departments'), fn ($q, $v) => $q->whereIn('u.department_name', $v))
            ->when($context->ids('job_title_ids'), fn ($q, $v) => $q->whereIn('u.job_title_id', $v))
            // The toolbar search matches the job title, in any language.
            ->when($context->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $w->where('jt.name', 'like', $like)->orWhere('jt.name_en', 'like', $like)->orWhere('jt.name_ar', 'like', $like);
            }))
            ->get(['u.id', 'u.job_title_id', 'u.department_name', 'u.department_name_en', 'jt.name', 'jt.name_en', 'jt.name_ar']);
        if ($employees->isEmpty()) {
            return [];
        }

        $standing = QualificationStanding::forUsers($employees->pluck('id')->all(), $context->day('as_of'));
        $requiredCount = DB::table('job_title_qualification_skill')
            ->whereIn('job_title_id', $employees->pluck('job_title_id')->unique()->values()->all())
            ->selectRaw('job_title_id, COUNT(*) AS c')->groupBy('job_title_id')->pluck('c', 'job_title_id');
        $target = $this->target();
        $keys = $this->columnKeys($context);

        return $employees->groupBy('job_title_id')
            ->map(function ($group, $jobTitleId) use ($standing, $requiredCount, $target, $context, $keys) {
                $first = $group->first();
                $required = (int) ($requiredCount[$jobTitleId] ?? 0);
                $compliant = $progress = $notStarted = 0;
                foreach ($group as $employee) {
                    $mine = array_filter($standing[(int) $employee->id] ?? [], fn (array $s) => $s['required']);
                    if ($required === 0) {
                        continue;
                    }
                    $held = count(array_filter($mine, fn (array $s) => $s['holds']));
                    $started = count(array_filter($mine, fn (array $s) => $s['started']));
                    match (true) {
                        $held >= $required => $compliant++,
                        $started > 0 => $progress++,
                        default => $notStarted++,
                    };
                }
                $total = $group->count();
                $pct = $required === 0 ? null : self::percent($compliant, $total);

                return self::shape([
                    'job_title' => Employee::localized($first->name_ar, $first->name_en, $first->name, $context),
                    'department' => $group
                        ->map(fn ($u) => Employee::department($u->department_name, $u->department_name_en, $context))
                        ->filter()->countBy()->sortDesc()->keys()->first(),
                    'total_employees' => $total,
                    'required_qualifications' => $required,
                    'fully_compliant' => $required === 0 ? null : $compliant,
                    'in_progress' => $required === 0 ? null : $progress,
                    'not_started' => $required === 0 ? null : $notStarted,
                    'compliance_pct' => $pct,
                    'target_pct' => (float) $target,
                    'compliance_status' => $pct === null ? null
                        : __($pct >= $target ? 'reports.values.compliance.on_track' : 'reports.values.compliance.at_risk'),
                ], $keys);
            })
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['job_title']))
            ->values()
            ->all();
    }

    /** The organisation-wide target (D-112); the lowest id row is canonical. */
    private function target(): int
    {
        $raw = Setting::query()->where('key', 'compliance_target')->orderBy('id')->value('value');

        return is_numeric($raw) ? max(0, min(100, (int) $raw)) : self::DEFAULT_TARGET;
    }
}
