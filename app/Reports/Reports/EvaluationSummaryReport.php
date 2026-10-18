<?php

namespace App\Reports\Reports;

use App\Reports\PreviewColumn;
use App\Reports\Column;
use App\Reports\ComputedReport;
use App\Reports\Filter;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\EvaluationTemplate;
use App\Reports\Support\Scope;
use App\Support\LocalizedJson;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 8. Evaluation Scores - Summary: one row per course x group x instructor
 * for one evaluation template (D-112).
 *
 * Responses (`user_course_evaluations`) carry the course and instructor; the
 * group is the respondent's cohort in that course (the form asks for none).
 * Evaluation Period is the first to the last submission (the open window is
 * not stored). One "Avg" column per rating question of the template, in its
 * order; Avg Overall Score is the mean of every rating answer. The template
 * has no Trainer / Program sections, so those two averages are left out.
 * The recommendation is the template's 1-10 question; NPS uses the standard
 * bands (9-10 promoter, 7-8 passive, 0-6 detractor). Course Code is not
 * captured.
 */
final class EvaluationSummaryReport extends ComputedReport
{
    /** @var array<string, list<Column>> */
    private array $columns = [];

    public function key(): string
    {
        return 'evaluation-summary';
    }

    public function columns(ReportContext $context): array
    {
        $template = $context->id('template_id');

        return $this->columns[$template.':'.$context->locale] ??= [
            new Column('course_name', 'course'),
            new Column('instructor', 'course'),
            new Column('group_cohort', 'course'),
            new Column('evaluation_period', 'course'),
            new Column('enrolled', 'response', Column::NUMBER),
            new Column('respondents', 'response', Column::NUMBER),
            new Column('response_rate', 'response', Column::PERCENT),
            new Column('departments_covered', 'response'),
            ...EvaluationTemplate::answerColumns($template, $context, averages: true),
            new Column('avg_overall_score', 'overall', Column::NUMBER),
            new Column('avg_recommendation', 'recommendation', Column::NUMBER),
            new Column('nps_bands', 'recommendation'),
            new Column('nps', 'recommendation', Column::NUMBER),
            new Column('comments_received', 'comments', Column::NUMBER),
            new Column('lowest_rated_question', 'comments'),
        ];
    }

    public function preview(ReportContext $context): array
    {
        return [
            PreviewColumn::of('course_name', 'reports.preview.course', Column::TEXT, true),
            PreviewColumn::of('instructor', 'reports.columns.instructor'),
            PreviewColumn::of('group_cohort', 'reports.columns.group_cohort'),
            PreviewColumn::of('respondents', 'reports.columns.respondents', Column::NUMBER, true),
            PreviewColumn::of('response_rate', 'reports.columns.response_rate', Column::PERCENT),
            PreviewColumn::of('avg_overall_score', 'reports.columns.avg_overall_score', Column::NUMBER),
            PreviewColumn::of('nps', 'reports.columns.nps', Column::NUMBER),
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

    /** Response rows of the template, with the respondent's cohort. */
    public static function responses(ReportContext $context): Builder
    {
        return DB::table('user_course_evaluations as uce')
            ->join('users as u', 'u.id', '=', 'uce.user_id')
            ->leftJoin('users_courses as ucx', function ($j) {
                $j->on('ucx.user_id', '=', 'uce.user_id')->on('ucx.course_id', '=', 'uce.course_id');
            })
            ->where('uce.evaluation_category_id', $context->id('template_id') ?? 0)
            ->tap(fn ($q) => Scope::courses($q, $context, 'uce.course_id'))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('uce.course_id', $v))
            ->when($context->ids('cohort_ids'), fn ($q, $v) => $q->whereIn('ucx.group_id', $v))
            ->when($context->ids('instructor_ids'), fn ($q, $v) => $q->whereIn('uce.instructor_id', $v))
            ->when($context->strings('departments'), fn ($q, $v) => $q->whereIn('u.department_name', $v))
            // The toolbar search matches the course (its name at answer time).
            ->when($context->search, fn ($q, $term) => $q->where('uce.course_name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($context->from('submitted'), fn ($q, $d) => $q->where('uce.created_at', '>=', $d))
            ->when($context->to('submitted'), fn ($q, $d) => $q->where('uce.created_at', '<=', $d));
    }

    protected function build(ReportContext $context): array
    {
        $questions = collect(EvaluationTemplate::questions($context->id('template_id'), $context))->keyBy('id');
        if ($questions->isEmpty()) {
            return [];
        }

        $groupKey = "CONCAT(uce.course_id, ':', COALESCE(ucx.group_id, 0), ':', COALESCE(uce.instructor_id, 0))";
        $base = fn () => self::responses($context)->selectRaw("{$groupKey} AS group_key");

        $groups = $base()
            ->groupBy('uce.course_id', 'ucx.group_id', 'uce.instructor_id')
            ->selectRaw('uce.course_id, ucx.group_id, uce.instructor_id, MAX(uce.instructor_name) AS instructor_snapshot')
            ->selectRaw('COUNT(DISTINCT uce.user_id) AS respondents, MIN(uce.created_at) AS first_at, MAX(uce.created_at) AS last_at')
            ->get();
        if ($groups->isEmpty()) {
            return [];
        }

        // Per group and question: the mean answer and the answer count.
        $perQuestion = [];
        foreach ($base()->where('uce.evaluation_type', '>', 0)
            ->groupBy('uce.course_id', 'ucx.group_id', 'uce.instructor_id', 'uce.evaluation_id')
            ->selectRaw('uce.evaluation_id, AVG(CAST(uce.answer AS DECIMAL(10,4))) AS avg_answer, COUNT(*) AS answers')
            ->get() as $row) {
            $perQuestion[$row->group_key][(int) $row->evaluation_id] = ['avg' => (float) $row->avg_answer, 'n' => (int) $row->answers];
        }
        $recommendationIds = $questions->filter(fn ($q) => EvaluationTemplate::isRecommendation($q))->keys()->all();
        $bands = [];
        if ($recommendationIds !== []) {
            foreach ($base()->whereIn('uce.evaluation_id', $recommendationIds)
                ->groupBy('uce.course_id', 'ucx.group_id', 'uce.instructor_id')
                ->selectRaw('SUM(CASE WHEN CAST(uce.answer AS DECIMAL(10,4)) >= 9 THEN 1 ELSE 0 END) AS promoters')
                ->selectRaw('SUM(CASE WHEN CAST(uce.answer AS DECIMAL(10,4)) >= 7 AND CAST(uce.answer AS DECIMAL(10,4)) < 9 THEN 1 ELSE 0 END) AS passives')
                ->selectRaw('SUM(CASE WHEN CAST(uce.answer AS DECIMAL(10,4)) < 7 THEN 1 ELSE 0 END) AS detractors')
                ->selectRaw('AVG(CAST(uce.answer AS DECIMAL(10,4))) AS avg_answer')
                ->get() as $row) {
                $bands[$row->group_key] = $row;
            }
        }
        $comments = $base()->where('uce.evaluation_type', 0)->whereRaw("TRIM(COALESCE(uce.answer, '')) <> ''")
            ->groupBy('uce.course_id', 'ucx.group_id', 'uce.instructor_id')
            ->selectRaw('COUNT(*) AS c')->pluck('c', 'group_key');
        $departments = $base()->groupBy('uce.course_id', 'ucx.group_id', 'uce.instructor_id', 'u.department_name', 'u.department_name_en')
            ->selectRaw('u.department_name, u.department_name_en')->get()->groupBy('group_key');

        $courseIds = $groups->pluck('course_id')->unique()->values()->all();
        $courses = DB::table('courses')->whereIn('id', $courseIds)->pluck('title', 'id');
        $cohorts = DB::table('course_sections')->whereIn('id', $groups->pluck('group_id')->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');
        $instructors = DB::table('instructors')->whereIn('id', $groups->pluck('instructor_id')->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');
        $enrolled = DB::table('users_courses')->whereIn('course_id', $courseIds)
            ->groupBy('course_id', 'group_id')->selectRaw("CONCAT(course_id, ':', COALESCE(group_id, 0)) AS k, COUNT(*) AS c")->pluck('c', 'k');
        $keys = $this->columnKeys($context);

        return $groups->map(function (object $g) use ($questions, $perQuestion, $bands, $comments, $departments, $courses, $cohorts, $instructors, $enrolled, $context, $keys) {
            $answers = $perQuestion[$g->group_key] ?? [];
            $values = [];
            $ratingSum = 0.0;
            $ratingCount = 0;
            $lowest = null;
            foreach ($questions as $id => $question) {
                if (! EvaluationTemplate::isRating($question) || ! isset($answers[$id])) {
                    continue;
                }
                $avg = round($answers[$id]['avg'], 1);
                $values[EvaluationTemplate::questionKey($question)] = $avg;
                $ratingSum += $answers[$id]['avg'] * $answers[$id]['n'];
                $ratingCount += $answers[$id]['n'];
                if ($lowest === null || $answers[$id]['avg'] < $lowest[1]) {
                    $lowest = [$question->title, $answers[$id]['avg']];
                }
            }
            $band = $bands[$g->group_key] ?? null;
            $bandTotal = $band ? (int) $band->promoters + (int) $band->passives + (int) $band->detractors : 0;
            $enrolledCount = (int) ($enrolled[$g->course_id.':'.($g->group_id ?? 0)] ?? 0);
            $period = [Employee::date($g->first_at), Employee::date($g->last_at)];

            return self::shape([
                'course_name' => LocalizedJson::pick($courses[$g->course_id] ?? null, $context->locale),
                'instructor' => LocalizedJson::pick($instructors[$g->instructor_id] ?? null, $context->locale) ?? Employee::text($g->instructor_snapshot),
                'group_cohort' => LocalizedJson::pick($cohorts[$g->group_id] ?? null, $context->locale),
                'evaluation_period' => $period[0] === $period[1] ? $period[0] : $period[0].' - '.$period[1],
                'enrolled' => $enrolledCount,
                'respondents' => (int) $g->respondents,
                'response_rate' => $enrolledCount > 0 ? min(100.0, (float) self::percent((int) $g->respondents, $enrolledCount)) : null,
                'departments_covered' => ($departments[$g->group_key] ?? collect())
                    ->map(fn ($d) => Employee::department($d->department_name, $d->department_name_en, $context))
                    ->filter()->unique()->sort()->implode(', ') ?: null,
                ...$values,
                'avg_overall_score' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 1) : null,
                'avg_recommendation' => $band && $band->avg_answer !== null ? round((float) $band->avg_answer, 1) : null,
                'nps_bands' => $bandTotal > 0 ? "{$band->promoters} / {$band->passives} / {$band->detractors}" : null,
                'nps' => $bandTotal > 0 ? (int) round(((int) $band->promoters - (int) $band->detractors) * 100 / $bandTotal) : null,
                'comments_received' => (int) ($comments[$g->group_key] ?? 0),
                'lowest_rated_question' => $lowest[0] ?? null,
            ], $keys);
        })
            ->sortBy([['course_name', 'asc'], ['group_cohort', 'asc'], ['instructor', 'asc']])
            ->values()
            ->all();
    }
}
