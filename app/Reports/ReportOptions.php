<?php

namespace App\Reports;

use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Support\LocalizedJson;
use App\Support\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Choices for the report filters, limited to the reader's course scope
 * (D-074), searchable and bounded. labels() names chosen values for the
 * export header's "Filters Applied" line.
 */
final class ReportOptions
{
    public const SOURCES = [
        'departments', 'job_titles', 'employees', 'qualifications', 'categories', 'courses', 'cohorts',
        'quizzes', 'assignments', 'graders', 'instructors', 'templates', 'providers',
    ];

    /** Sources whose values are the stored text itself, not record ids. */
    public const STRING_SOURCES = ['departments', 'providers'];

    public const LIMIT = 50;

    /**
     * @param  list<int>  $courseIds  narrows cohorts, quizzes and assignments to these courses
     * @return list<array{id: int|string, label: string}>
     */
    public function options(string $source, ReportContext $context, ?string $search = null, array $courseIds = []): array
    {
        return $this->query($source, $context, $courseIds)
            ->when($search !== null && $search !== '', fn (Builder $q) => $this->search($q, $source, (string) $search))
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (object $row) => $this->option($source, $row, $context))
            ->filter(fn (array $o) => $o['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * Labels for values already chosen, in the order given.
     *
     * @param  list<int|string>  $values
     * @return list<string>
     */
    public function labels(string $source, array $values, ReportContext $context): array
    {
        if ($values === []) {
            return [];
        }
        $key = $this->idColumn($source);
        $found = $this->query($source, $context, [])
            ->whereIn($key, $values)
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->id => $this->option($source, $row, $context)['label']]);

        return array_values(array_filter(array_map(fn ($v) => $found[(string) $v] ?? null, $values)));
    }

    private function query(string $source, ReportContext $context, array $courseIds): Builder
    {
        $courseFilter = fn (Builder $q, string $column) => Scope::courses($q, $context, $column)
            ->when($courseIds !== [], fn ($w) => $w->whereIn($column, $courseIds));

        return match ($source) {
            'departments' => Scope::learners(DB::table('users as u'), $context, 'u.id')
                ->whereNotNull('u.department_name')->where('u.department_name', '<>', '')
                ->groupBy('u.department_name')
                ->selectRaw('u.department_name AS id, MAX(u.department_name_en) AS name_en')
                ->orderBy('u.department_name'),
            'job_titles' => DB::table('job_titles')->select(['id', 'name', 'name_en', 'name_ar'])->orderBy('name'),
            'employees' => Scope::learners(DB::table('users as u'), $context, 'u.id')
                ->select(['u.id', 'u.name', 'u.name_en', 'u.name_ar', 'u.machine_code'])
                ->orderByRaw(PersonName::userSql('u', $context->locale)),
            'qualifications' => DB::table('qualification_skills')->select(['id', 'name'])->orderBy('id'),
            'categories' => DB::table('categories')->select(['id', 'name'])->orderBy('id'),
            'courses' => $courseFilter(DB::table('courses'), 'courses.id')->select(['courses.id', 'courses.title as name'])->orderByDesc('courses.id'),
            'cohorts' => $courseFilter(DB::table('course_sections as cs')->join('courses as c', 'c.id', '=', 'cs.course_id'), 'cs.course_id')
                ->select(['cs.id', 'cs.name', 'c.title as course_title'])->orderByDesc('cs.start_date')->orderByDesc('cs.id'),
            'quizzes' => $courseFilter(DB::table('course_exams'), 'course_exams.course_id')
                ->select(['course_exams.id', 'course_exams.title as name'])->orderByDesc('course_exams.id'),
            'assignments' => $courseFilter(DB::table('course_assignments'), 'course_assignments.course_id')
                ->select(['course_assignments.id', 'course_assignments.title as name', 'course_assignments.title_ar'])->orderByDesc('course_assignments.id'),
            'graders' => DB::table('admins')->whereIn('admins.id', DB::table('user_course_assignments')->select('reviewed_by')->whereNotNull('reviewed_by'))
                ->select(['admins.id', 'admins.name', 'admins.name_ar'])->orderBy('admins.name'),
            'instructors' => DB::table('instructors')
                ->when($context->courseIds !== null, fn ($q) => $q->whereIn('instructors.id', DB::table('courses_instructors')
                    ->select('instructor_id')->whereIn('course_id', $context->courseIds ?: [0])))
                ->select(['instructors.id', 'instructors.name'])->orderBy('instructors.id'),
            // Templates with answers first: the most answered is the default choice.
            'templates' => DB::table('evaluation_categories as t')
                ->leftJoinSub(DB::table('user_course_evaluations')->tap(fn ($q) => Scope::courses($q, $context, 'course_id'))
                    ->groupBy('evaluation_category_id')->selectRaw('evaluation_category_id, COUNT(*) AS answers'), 'a', 'a.evaluation_category_id', '=', 't.id')
                ->select(['t.id', 't.name'])->orderByRaw('COALESCE(a.answers, 0) DESC')->orderByDesc('t.id'),
            'providers' => Scope::learners(DB::table('external_training_requests as e'), $context, 'e.user_id')
                ->groupBy('e.provider')->selectRaw('e.provider AS id')->orderBy('e.provider'),
            default => throw new \InvalidArgumentException("Unknown option source [{$source}]."),
        };
    }

    private function search(Builder $query, string $source, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        match ($source) {
            'departments' => $query->where(fn ($w) => $w->where('u.department_name', 'like', $like)->orWhere('u.department_name_en', 'like', $like)),
            'job_titles' => $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)),
            'employees' => PersonName::whereUserMatches($query, 'u', $term, ['machine_code', 'email']),
            'courses' => $query->where('courses.title', 'like', $like),
            'cohorts' => $query->where(fn ($w) => $w->where('cs.name', 'like', $like)->orWhere('c.title', 'like', $like)),
            'quizzes' => $query->where('course_exams.title', 'like', $like),
            'assignments' => $query->where(fn ($w) => $w->where('course_assignments.title', 'like', $like)->orWhere('course_assignments.title_ar', 'like', $like)),
            'graders' => $query->where(fn ($w) => $w->where('admins.name', 'like', $like)->orWhere('admins.name_ar', 'like', $like)),
            'instructors' => $query->where('instructors.name', 'like', $like),
            'templates' => $query->where('t.name', 'like', $like),
            'providers' => $query->where('e.provider', 'like', $like),
            default => $query->where('name', 'like', $like),
        };
    }

    private function idColumn(string $source): string
    {
        return match ($source) {
            'departments' => 'u.department_name',
            'employees' => 'u.id',
            'courses' => 'courses.id',
            'cohorts' => 'cs.id',
            'quizzes' => 'course_exams.id',
            'assignments' => 'course_assignments.id',
            'graders' => 'admins.id',
            'instructors' => 'instructors.id',
            'templates' => 't.id',
            'providers' => 'e.provider',
            default => 'id',
        };
    }

    /** @return array{id: int|string, label: string} */
    private function option(string $source, object $row, ReportContext $context): array
    {
        $label = match ($source) {
            'departments' => Employee::department($row->id, $row->name_en ?? null, $context),
            'providers' => (string) $row->id,
            'job_titles' => Employee::localized($row->name_ar, $row->name_en, $row->name, $context),
            'employees' => trim(collect([
                $context->isArabic() ? (Employee::text($row->name_ar) ?? Employee::text($row->name_en)) : (Employee::text($row->name_en) ?? Employee::text($row->name_ar)),
            ])->filter()->first() ?? (string) $row->name).($row->machine_code ? ' ('.$row->machine_code.')' : ''),
            'graders' => $context->isArabic() ? (Employee::text($row->name_ar) ?? (string) $row->name) : ((string) ($row->name ?: $row->name_ar)),
            'cohorts' => trim((LocalizedJson::pick($row->name, $context->locale) ?? '').' - '.(LocalizedJson::pick($row->course_title, $context->locale) ?? ''), ' -'),
            'assignments' => $context->isArabic() && Employee::text($row->title_ar) ? $row->title_ar : (LocalizedJson::pick($row->name, $context->locale) ?? $row->title_ar),
            default => LocalizedJson::pick($row->name, $context->locale),
        };

        return ['id' => in_array($source, self::STRING_SOURCES, true) ? (string) $row->id : (int) $row->id, 'label' => (string) ($label ?? '')];
    }
}
