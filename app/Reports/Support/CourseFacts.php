<?php

namespace App\Reports\Support;

use App\Models\Course;
use App\Reports\ReportContext;
use App\Services\CertificatePolicy;
use App\Support\LocalizedJson;
use Illuminate\Support\Facades\DB;

/**
 * Course and cohort facts several reports share, loaded once per batch.
 */
final class CourseFacts
{
    /**
     * Instructor names per course, in the reader's language, comma separated.
     *
     * @param  list<int>  $courseIds
     * @return array<int, string>
     */
    public static function instructors(array $courseIds, ReportContext $context): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('courses_instructors as ci')
            ->join('instructors as i', 'i.id', '=', 'ci.instructor_id')
            ->whereIn('ci.course_id', $courseIds)
            ->orderBy('i.id')
            ->get(['ci.course_id', 'i.name'])
            ->groupBy('course_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => LocalizedJson::pick($r->name, $context->locale))->filter()->unique()->implode(', '))
            ->all();
    }

    /**
     * Per cohort: its session rows, how many have been held, and the mean
     * planned length of a session in hours (time_to - time_from).
     *
     * @param  list<int>  $cohortIds
     * @return array<int, array{rows:int, held:int, hours:?float}>
     */
    public static function cohortSessions(array $cohortIds): array
    {
        if ($cohortIds === []) {
            return [];
        }
        $today = now()->toDateString();
        $now = now()->format('H:i:s');

        $out = [];
        foreach (DB::table('course_sessions')
            ->whereIn('section_id', $cohortIds)
            ->groupBy('section_id')
            ->selectRaw('section_id, COUNT(*) AS rows_count')
            ->selectRaw('SUM(CASE WHEN session_date < ? OR (session_date = ? AND time_to IS NOT NULL AND time_to <= ?) THEN 1 ELSE 0 END) AS held', [$today, $today, $now])
            ->selectRaw('AVG(CASE WHEN time_from IS NOT NULL AND time_to IS NOT NULL AND time_to > time_from'
                .' THEN TIME_TO_SEC(TIMEDIFF(time_to, time_from)) END) / 3600 AS hours')
            ->get() as $row) {
            $out[(int) $row->section_id] = [
                'rows' => (int) $row->rows_count,
                'held' => (int) $row->held,
                'hours' => $row->hours === null ? null : round((float) $row->hours, 2),
            ];
        }

        return $out;
    }

    /**
     * The attendance threshold each course's certificate rule uses (D-084,
     * D-094): the course's own rule when it opted out, else Platform Config.
     *
     * @param  list<int>  $courseIds
     * @return array<int, int>
     */
    public static function attendanceThresholds(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }
        $policy = app(CertificatePolicy::class);

        return Course::query()->whereIn('id', $courseIds)
            ->get(['id', 'certificate_custom_rule', 'certificate_mode', 'certificate_attendance_threshold', 'certificate_score_threshold'])
            ->mapWithKeys(fn (Course $c) => [(int) $c->id => $policy->forCourse($c)->minAttendance])
            ->all();
    }

    public static function deliveryMode(?string $type): ?string
    {
        return $type === null || $type === '' ? null : __("enums.course_type.{$type}");
    }
}
