<?php

namespace App\Reports\Reports;

use App\Models\Admin;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\EvaluationTemplate;
use App\Support\LocalizedJson;
use App\Support\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 8b. Evaluation Scores - Response Detail: one row per submitted form, with
 * every answer, for one evaluation template (D-112).
 *
 * A submission is a learner's answers to the template in one course (there
 * is no submission table); Response ID is its first answer row's id, stable
 * and unique. Responses name the respondent, so this report needs the
 * evaluations permission on top of reports (human 2026-10-18).
 */
final class EvaluationResponsesReport extends QueryReport
{
    /** @var array<string, list<Column>> */
    private array $columns = [];

    public function key(): string
    {
        return 'evaluation-responses';
    }

    public function allows(Admin $admin): bool
    {
        return $admin->canInSection('evaluations', 'view');
    }

    public function columns(ReportContext $context): array
    {
        $template = $context->id('template_id');

        return $this->columns[$template.':'.$context->locale] ??= [
            new Column('response_id', 'response'),
            new Column('submission_date', 'response', Column::DATETIME),
            new Column('full_name_ar', 'employee'),
            new Column('employee_id', 'employee'),
            new Column('department', 'employee'),
            new Column('job_title', 'employee'),
            new Column('course_name', 'course'),
            new Column('instructor', 'course'),
            new Column('group_cohort', 'course'),
            ...EvaluationTemplate::answerColumns($template, $context, averages: false),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('template_id', 'templates', required: true),
            Filter::multi('course_ids', 'courses'),
            Filter::multi('cohort_ids', 'cohorts', dependsOn: 'course_ids'),
            Filter::multi('instructor_ids', 'instructors'),
            Filter::multi('departments', 'departments'),
            Filter::dateRange('submitted'),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        // The toolbar search matches the respondent here, not the course.
        $responses = new ReportContext($context->filters, $context->locale, $context->courseIds);

        return EvaluationSummaryReport::responses($responses)
            ->tap(fn ($q) => Employee::select($q))
            ->when($context->search, fn ($q, $term) => PersonName::whereUserMatches($q, 'u', $term, ['machine_code', 'email']))
            ->groupBy('uce.user_id', 'uce.course_id')
            ->select([])
            ->selectRaw('MIN(uce.id) AS response_id, uce.user_id, uce.course_id, MIN(uce.created_at) AS submitted_at')
            ->selectRaw('MAX(uce.instructor_id) AS instructor_id, MAX(uce.instructor_name) AS instructor_snapshot')
            ->selectRaw('MAX(uce.user_machine_code) AS code_snapshot, MAX(uce.user_department) AS department_snapshot')
            ->selectRaw('MAX(ucx.group_id) AS group_id')
            ->tap(fn ($q) => self::employeeAggregates($q))
            ->orderByRaw('MIN(uce.created_at) DESC')
            ->orderByRaw('MIN(uce.id) DESC');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $template = $context->id('template_id');
        $answers = [];
        if ($rows->isNotEmpty()) {
            foreach (DB::table('user_course_evaluations')
                ->where('evaluation_category_id', $template)
                ->whereIn('user_id', $rows->pluck('user_id')->unique()->values()->all())
                ->whereIn('course_id', $rows->pluck('course_id')->unique()->values()->all())
                ->orderBy('id')
                ->get(['user_id', 'course_id', 'evaluation_id', 'evaluation_type', 'answer']) as $a) {
                $answers[$a->user_id.':'.$a->course_id][(int) $a->evaluation_id] = (int) $a->evaluation_type > 0 && is_numeric($a->answer)
                    ? (float) $a->answer + 0
                    : Employee::text($a->answer);
            }
        }
        $questions = EvaluationTemplate::questions($template, $context);
        $courses = DB::table('courses')->whereIn('id', $rows->pluck('course_id')->unique()->values()->all() ?: [0])->pluck('title', 'id');
        $cohorts = DB::table('course_sections')->whereIn('id', $rows->pluck('group_id')->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');
        $instructors = DB::table('instructors')->whereIn('id', $rows->pluck('instructor_id')->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($answers, $questions, $courses, $cohorts, $instructors, $context, $keys) {
            $values = [];
            foreach ($questions as $question) {
                $values[EvaluationTemplate::questionKey($question)] = $answers[$row->user_id.':'.$row->course_id][$question->id] ?? null;
            }
            $employee = Employee::values($row, $context);

            return self::shape([
                'response_id' => (string) $row->response_id,
                'submission_date' => Employee::dateTime($row->submitted_at),
                'full_name_ar' => $employee['full_name_ar'],
                'employee_id' => $employee['employee_id'] ?? Employee::text($row->code_snapshot),
                'department' => $employee['department'] ?? Employee::text($row->department_snapshot),
                'job_title' => $employee['job_title'],
                'course_name' => LocalizedJson::pick($courses[$row->course_id] ?? null, $context->locale),
                'instructor' => LocalizedJson::pick($instructors[$row->instructor_id] ?? null, $context->locale) ?? Employee::text($row->instructor_snapshot),
                'group_cohort' => LocalizedJson::pick($cohorts[$row->group_id] ?? null, $context->locale),
                ...$values,
            ], $keys);
        })->values()->all();
    }

    /**
     * Employee::select() columns, aggregated: one user per group, so MAX()
     * reads that user's value under ONLY_FULL_GROUP_BY.
     */
    private static function employeeAggregates(Builder $query): void
    {
        foreach ([
            'u.id' => 'emp_user_id', 'u.machine_code' => 'emp_code', 'u.name_en' => 'emp_name_en', 'u.name_ar' => 'emp_name_ar',
            'u.name' => 'emp_name', 'u.email' => 'emp_email', 'u_jt.name' => 'emp_jt', 'u_jt.name_en' => 'emp_jt_en',
            'u_jt.name_ar' => 'emp_jt_ar', 'u.department_name' => 'emp_dept', 'u.department_name_en' => 'emp_dept_en',
            'u.location_name' => 'emp_loc', 'u.location_name_en' => 'emp_loc_en', 'u.hr_employee_status' => 'emp_hr_status',
            'u.resignation_date' => 'emp_resigned', 'u.hire_date' => 'emp_hired',
        ] as $column => $alias) {
            $query->selectRaw("MAX({$column}) AS {$alias}");
        }
    }
}
