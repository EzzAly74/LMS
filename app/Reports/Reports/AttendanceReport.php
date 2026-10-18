<?php

namespace App\Reports\Reports;

use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\CourseFacts;
use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Support\LocalizedJson;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 3. Attendance: one row per learner x course x group (D-112), in the spec's
 * column order (the current file's ten columns first, then the additions).
 *
 * Only enrolments that take attendance: the learner's cohort has sessions,
 * or the learner has attendance in the course.
 *
 *   Required Sessions   the cohort's session rows, else its planned number,
 *                       else the course's (the certificate's rule, B-182)
 *   Sessions Attended   distinct sessions attended (legacy rows without a
 *                       session: their count)
 *   Hours per Session   mean planned session length of the cohort, else
 *                       the cohort's average session time
 *   Attendance Hours    sum of the learner's attendance rows
 *
 * Both percentages are reported, so the 357 rows where hours and sessions
 * disagree (spec, open question 7) stay visible. Attendance Status uses the
 * course's certificate attendance threshold (D-084, D-094), so it never
 * contradicts the certificate: No Show (nothing attended once a session was
 * held), Completed (at or over it), At Risk (cannot reach it even attending
 * every remaining session), Partial (otherwise); blank before any session.
 */
final class AttendanceReport extends QueryReport
{
    private const REQUIRED_SQL = 'COALESCE(NULLIF((SELECT COUNT(*) FROM course_sessions rs WHERE rs.section_id = uc.group_id), 0), cs.number_of_sessions, c.number_of_sessions)';

    private const ATTENDED_SQL = '(SELECT CASE WHEN COUNT(DISTINCT a.session_id) > 0 THEN COUNT(DISTINCT a.session_id) ELSE COUNT(*) END'
        .' FROM attendances a WHERE a.user_id = uc.user_id AND a.course_id = uc.course_id)';

    public function key(): string
    {
        return 'attendance';
    }

    public function columns(ReportContext $context): array
    {
        return [
            new Column('employee_id', 'employee'),
            new Column('full_name_ar', 'employee'),
            new Column('department', 'employee'),
            new Column('course_name', 'course'),
            new Column('group_cohort', 'course'),
            new Column('category', 'course'),
            new Column('required_sessions', 'sessions', Column::NUMBER),
            new Column('sessions_attended', 'sessions', Column::NUMBER),
            new Column('hours_per_session', 'hours', Column::NUMBER),
            new Column('attendance_hours', 'hours', Column::NUMBER),
            new Column('full_name_en', 'employee'),
            new Column('job_title', 'employee'),
            Column::missing('line_manager', 'employee'),
            new Column('location', 'employee'),
            new Column('employment_status', 'employee'),
            Column::missing('course_code', 'course'),
            new Column('delivery_mode', 'course'),
            new Column('instructor', 'course'),
            new Column('group_start_date', 'course', Column::DATE),
            new Column('group_end_date', 'course', Column::DATE),
            new Column('attendance_pct_sessions', 'calculated', Column::PERCENT),
            new Column('total_course_hours', 'calculated', Column::NUMBER),
            new Column('attendance_pct_hours', 'calculated', Column::PERCENT),
            new Column('attendance_status', 'calculated'),
            new Column('absence_hours', 'calculated', Column::NUMBER),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('departments', 'departments'),
            Filter::multi('category_ids', 'categories'),
            Filter::multi('course_ids', 'courses'),
            Filter::multi('cohort_ids', 'cohorts', dependsOn: 'course_ids'),
            Filter::dateRange('period'),
            Filter::number('attendance_below'),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $from = $context->from('period');
        $to = $context->to('period');
        $below = $context->number('attendance_below');

        $query = DB::table('users_courses as uc')
            ->join('users as u', 'u.id', '=', 'uc.user_id')
            ->join('courses as c', 'c.id', '=', 'uc.course_id')
            ->leftJoin('course_sections as cs', 'cs.id', '=', 'uc.group_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'c.category_id')
            ->select([
                'uc.user_id', 'uc.course_id', 'uc.group_id',
                'c.title as course_title', 'c.course_type', 'c.hours as course_hours',
                'cs.name as cohort_name', 'cs.start_date as cohort_start', 'cs.end_date as cohort_end',
                'cs.avg_session_time', 'cat.name as category_name',
            ])
            ->selectRaw(self::REQUIRED_SQL.' AS required_sessions')
            ->selectRaw(self::ATTENDED_SQL.' AS sessions_attended')
            ->selectRaw('(SELECT SUM(a.attendance_hours) FROM attendances a WHERE a.user_id = uc.user_id AND a.course_id = uc.course_id) AS attendance_hours')
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::courses($q, $context, 'uc.course_id'))
            ->where(fn ($w) => $w
                ->whereExists(fn ($e) => $e->selectRaw('1')->from('course_sessions as es')->whereColumn('es.section_id', 'uc.group_id'))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('attendances as ea')
                    ->whereColumn('ea.user_id', 'uc.user_id')->whereColumn('ea.course_id', 'uc.course_id')))
            ->when($context->ids('category_ids'), fn ($q, $v) => $q->whereIn('c.category_id', $v))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('uc.course_id', $v))
            ->when($context->ids('cohort_ids'), fn ($q, $v) => $q->whereIn('uc.group_id', $v))
            // A cohort that ran at any time in the period.
            ->when($to, fn ($q) => $q->whereDate('cs.start_date', '<=', $to->toDateString()))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('cs.end_date')->orWhereDate('cs.end_date', '>=', $from->toDateString())));

        if ($below !== null) {
            $query->whereRaw('('.self::REQUIRED_SQL.') > 0')
                ->whereRaw('LEAST(100, '.self::ATTENDED_SQL.' * 100 / ('.self::REQUIRED_SQL.')) < ?', [$below]);
        }

        return $query
            ->orderByRaw('COALESCE(NULLIF(u.name_ar, \'\'), u.name)')
            ->orderBy('uc.user_id')
            ->orderBy('uc.course_id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $courseIds = $rows->pluck('course_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $sessions = CourseFacts::cohortSessions($rows->pluck('group_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());
        $instructors = CourseFacts::instructors($courseIds, $context);
        $thresholds = CourseFacts::attendanceThresholds($courseIds);
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($sessions, $instructors, $thresholds, $context, $keys) {
            $cohort = $row->group_id ? ($sessions[(int) $row->group_id] ?? null) : null;
            $required = $row->required_sessions === null ? null : (int) $row->required_sessions;
            $attended = (int) $row->sessions_attended;
            $hoursPerSession = $cohort['hours'] ?? ($row->avg_session_time !== null && (float) $row->avg_session_time > 0
                ? round((float) $row->avg_session_time, 2)
                : ($required && $row->course_hours ? round((float) $row->course_hours / $required, 2) : null));
            $attendanceHours = round((float) ($row->attendance_hours ?? 0), 2);
            $totalHours = $required && $hoursPerSession !== null ? round($required * $hoursPerSession, 2) : null;
            $pctSessions = $required ? min(100.0, (float) self::percent($attended, $required)) : null;

            return self::shape([
                ...Employee::values($row, $context),
                'course_name' => LocalizedJson::pick($row->course_title, $context->locale),
                'group_cohort' => LocalizedJson::pick($row->cohort_name, $context->locale),
                'category' => LocalizedJson::pick($row->category_name, $context->locale),
                'required_sessions' => $required,
                'sessions_attended' => $attended,
                'hours_per_session' => $hoursPerSession,
                'attendance_hours' => $attendanceHours,
                'delivery_mode' => CourseFacts::deliveryMode($row->course_type),
                'instructor' => $instructors[(int) $row->course_id] ?? null,
                'group_start_date' => Employee::date($row->cohort_start),
                'group_end_date' => Employee::date($row->cohort_end),
                'attendance_pct_sessions' => $pctSessions,
                'total_course_hours' => $totalHours,
                'attendance_pct_hours' => $totalHours ? self::percent($attendanceHours, $totalHours) : null,
                'attendance_status' => self::status($attended, $required, $cohort['held'] ?? null, $thresholds[(int) $row->course_id] ?? null),
                'absence_hours' => $totalHours === null ? null : max(0.0, round($totalHours - $attendanceHours, 2)),
            ], $keys);
        })->values()->all();
    }

    /** Completed / Partial / At Risk / No Show against the course's attendance threshold. */
    private static function status(int $attended, ?int $required, ?int $held, ?int $threshold): ?string
    {
        if (! $required || $threshold === null) {
            return null;
        }
        $held ??= $attended;
        if ($held === 0 && $attended === 0) {
            return null;
        }
        $key = match (true) {
            $attended === 0 => 'no_show',
            $attended * 100 / $required >= $threshold => 'completed',
            ($attended + max(0, $required - $held)) * 100 / $required < $threshold => 'at_risk',
            default => 'partial',
        };

        return __("reports.values.attendance.{$key}");
    }
}
