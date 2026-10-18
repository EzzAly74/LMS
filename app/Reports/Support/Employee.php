<?php

namespace App\Reports\Support;

use App\Reports\Column;
use App\Reports\ReportContext;
use App\Support\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * The employee fields the NAS reports repeat (the spec's grey rows), read
 * from `users` and its job title. Employee ID is the HR employee code
 * (`machine_code`), the number HR, attendance and certificates all show.
 * Line Manager is not in the HR feed, so it is left out (D-112).
 */
final class Employee
{
    /** The standard block, in the spec's order. @return list<Column> */
    public static function columns(): array
    {
        return [
            new Column('employee_id', 'employee'),
            new Column('full_name_en', 'employee'),
            new Column('full_name_ar', 'employee'),
            new Column('email', 'employee'),
            new Column('job_title', 'employee'),
            new Column('department', 'employee'),
            new Column('location', 'employee'),
            new Column('employment_status', 'employee'),
            new Column('hire_date', 'employee', Column::DATE),
        ];
    }

    /** Add the employee fields of `users` alias `$u` (job title joined as `{$u}_jt`). */
    public static function select(Builder $query, string $u = 'u'): Builder
    {
        $jt = "{$u}_jt";

        return $query
            ->leftJoin("job_titles as {$jt}", "{$jt}.id", '=', "{$u}.job_title_id")
            ->addSelect([
                "{$u}.id as emp_user_id",
                "{$u}.machine_code as emp_code",
                "{$u}.name_en as emp_name_en",
                "{$u}.name_ar as emp_name_ar",
                "{$u}.name as emp_name",
                "{$u}.email as emp_email",
                "{$jt}.name as emp_jt",
                "{$jt}.name_en as emp_jt_en",
                "{$jt}.name_ar as emp_jt_ar",
                "{$u}.department_name as emp_dept",
                "{$u}.department_name_en as emp_dept_en",
                "{$u}.location_name as emp_loc",
                "{$u}.location_name_en as emp_loc_en",
                "{$u}.hr_employee_status as emp_hr_status",
                "{$u}.resignation_date as emp_resigned",
                "{$u}.hire_date as emp_hired",
            ]);
    }

    /** The block's values for one row read with select(). @return array<string, mixed> */
    public static function values(object $row, ReportContext $context): array
    {
        return [
            'employee_id' => self::text($row->emp_code ?? null),
            'full_name_en' => self::text($row->emp_name_en ?? null),
            'full_name_ar' => self::text($row->emp_name_ar ?? null) ?? self::text($row->emp_name ?? null),
            'email' => self::text($row->emp_email ?? null),
            'job_title' => self::localized($row->emp_jt_ar ?? null, $row->emp_jt_en ?? null, $row->emp_jt ?? null, $context),
            'department' => self::department($row->emp_dept ?? null, $row->emp_dept_en ?? null, $context),
            'location' => $context->isArabic()
                ? (self::text($row->emp_loc ?? null) ?? self::text($row->emp_loc_en ?? null))
                : (self::text($row->emp_loc_en ?? null) ?? self::text($row->emp_loc ?? null)),
            'employment_status' => self::employmentStatus($row->emp_hr_status ?? null, $row->emp_resigned ?? null),
            'hire_date' => self::date($row->emp_hired ?? null),
        ];
    }

    /**
     * The common employee filters and the toolbar search, on `users` alias `$u`.
     * Departments are HR's Arabic names (the canonical value of the free-text column).
     */
    public static function filter(Builder $query, ReportContext $context, string $u = 'u'): Builder
    {
        return $query
            ->when($context->strings('departments'), fn (Builder $q, array $v) => $q->whereIn("{$u}.department_name", $v))
            ->when($context->ids('job_title_ids'), fn (Builder $q, array $v) => $q->whereIn("{$u}.job_title_id", $v))
            ->when($context->ids('employee_ids'), fn (Builder $q, array $v) => $q->whereIn("{$u}.id", $v))
            ->when($context->strings('org_units'), fn (Builder $q, array $v) => self::whereOrgUnits($q, $v, $u))
            ->when($context->search, fn (Builder $q, string $term) => PersonName::whereUserMatches($q, $u, $term, ['machine_code', 'email']));
    }

    /**
     * The combined Department / Job Title filter: any chosen department OR
     * any chosen job title.
     *
     * @param  list<string>  $values  "dept:<HR department name>" or "job:<job title id>"
     */
    private static function whereOrgUnits(Builder $query, array $values, string $u): Builder
    {
        $departments = [];
        $jobTitles = [];
        foreach ($values as $value) {
            if (str_starts_with($value, 'dept:')) {
                $departments[] = substr($value, 5);
            } elseif (str_starts_with($value, 'job:') && ctype_digit(substr($value, 4))) {
                $jobTitles[] = (int) substr($value, 4);
            }
        }

        return $query->where(function (Builder $w) use ($departments, $jobTitles, $u) {
            $w->whereIn("{$u}.department_name", $departments ?: ['\0'])
                ->orWhereIn("{$u}.job_title_id", $jobTitles ?: [0]);
        });
    }

    /**
     * Active / On Leave / Terminated from HR (D-112): a resignation date on or
     * before today is Terminated; otherwise HR's status code, mapped only for
     * the codes confirmed in config('hr.employee_statuses').
     */
    public static function employmentStatus(mixed $code, mixed $resignedOn): ?string
    {
        if ($resignedOn !== null && $resignedOn !== '' && Carbon::parse($resignedOn)->startOfDay()->lte(Carbon::today())) {
            return __('reports.values.employment.terminated');
        }
        if (! is_numeric($code)) {
            return null;
        }
        $value = config('hr.employee_statuses', [])[(int) $code] ?? null;

        return $value === null ? null : __("reports.values.employment.{$value}");
    }

    public static function department(?string $arabic, ?string $english, ReportContext $context): ?string
    {
        return $context->isArabic()
            ? (self::text($arabic) ?? self::text($english))
            : (self::text($english) ?? self::text($arabic));
    }

    public static function localized(?string $arabic, ?string $english, ?string $base, ReportContext $context): ?string
    {
        $first = $context->isArabic() ? $arabic : $english;

        return self::text($first) ?? self::text($base) ?? self::text($context->isArabic() ? $english : $arabic);
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    public static function dateTime(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i');
    }
}
