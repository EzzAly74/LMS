<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "Active & Completed Courses" (Figma 2181:115043):
 * Course + cohort, Attended, Absent, Status, Progress, Started on, Ended on.
 *
 * Backed by plain stdClass rows from a query builder, so properties are read
 * defensively.
 */
class AdminLearnerCourseRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scheduled = (int) ($this->sessions_scheduled ?? 0);
        $attended  = (int) ($this->sessions_attended ?? 0);

        return [
            'course_id'   => (int) $this->course_id,
            'course'      => $this->course_title,
            'cohort'      => $this->cohort_name,
            'cohort_id'   => $this->cohort_id !== null ? (int) $this->cohort_id : null,
            'attended'    => $attended,
            // Never negative: a learner can attend a session that was later
            // removed from the cohort schedule.
            'absent'      => max(0, $scheduled - $attended),
            'sessions_scheduled' => $scheduled,
            'status'      => $this->status,
            // Attendance-based progress. Null rather than 0 when the cohort has
            // no scheduled sessions, so the UI renders "—" instead of implying
            // the learner attended nothing.
            'progress_percent' => $scheduled > 0
                ? (int) round($attended * 100 / $scheduled)
                : null,
            'started_on'  => $this->start_date,
            'ended_on'    => $this->end_date,
            'enrolled_at' => $this->enrolled_at,
        ];
    }
}
