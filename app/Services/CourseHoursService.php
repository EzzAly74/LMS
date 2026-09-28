<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Support\Facades\DB;

/**
 * A course's `hours` is its scheduled session time (D-062, B-119).
 *
 * The figure shown on course cards (Website, mobile, the absence report) used
 * to be typed nowhere and defaulted to 1. It now follows the uploaded cohort
 * schedule: the sum of `time_to - time_from` over the sessions of the course's
 * current cohort, or its most recent one when none is running. Learners' own
 * hours are not affected - those come from actual attendance.
 */
class CourseHoursService
{
    /** Recompute and store `courses.hours`; returns the new value, or null when there is no schedule to derive from. */
    public function refresh(Course $course): ?int
    {
        $cohort = $this->referenceCohort($course);
        if ($cohort === null) {
            return null;
        }

        $hours = $this->scheduledHours($cohort);
        if ($hours === null) {
            return null;
        }

        // The column is an integer; a schedule always counts as at least an hour.
        $value = max(1, (int) round($hours));
        DB::table('courses')->where('id', $course->id)->update(['hours' => $value]);
        $course->hours = $value;

        return $value;
    }

    /** Total scheduled hours of one cohort, or null when none of its sessions has both times. */
    public function scheduledHours(CourseSection $cohort): ?float
    {
        $seconds = DB::table('course_sessions')
            ->where('section_id', $cohort->id)
            ->whereNotNull('time_from')
            ->whereNotNull('time_to')
            ->whereColumn('time_to', '>', 'time_from')
            ->sum(DB::raw('TIME_TO_SEC(time_to) - TIME_TO_SEC(time_from)'));

        return $seconds > 0 ? $seconds / 3600 : null;
    }

    /** The cohort running today, else the one that started last (or was created last). */
    private function referenceCohort(Course $course): ?CourseSection
    {
        $today = now()->toDateString();

        return CourseSection::query()
            ->where('course_id', $course->id)
            ->whereExists(fn ($q) => $q->from('course_sessions')->whereColumn('course_sessions.section_id', 'course_sections.id'))
            ->orderByRaw('CASE WHEN start_date <= ? AND (end_date IS NULL OR end_date >= ?) THEN 0 ELSE 1 END', [$today, $today])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }
}
