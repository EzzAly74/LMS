<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "View Learners scores" (Figma 2017:52260).
 *
 * The row is keyed by (learner, course, template) because there is no
 * submission id — the UI needs all three to open the detail.
 *
 * FG-12: the design shows scores as "/105" on this screen and "4.3/5.0"
 * elsewhere. This resource returns `total`, `max_total` and a normalised
 * `ratio` and lets the UI render whichever the designer settles on; it does not
 * choose.
 */
class AdminEvaluationScoreRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (float) ($this->total ?? 0);
        $max   = (float) ($this->max_total ?? 0);

        return [
            'learner' => [
                'id'          => (int) $this->user_id,
                'employee_id' => $this->user_machine_code,
                'department'  => $this->user_department,
            ],
            'course' => [
                'id'   => (int) $this->course_id,
                'name' => $this->course_name,
            ],
            'template' => [
                'id'   => $this->evaluation_category_id !== null ? (int) $this->evaluation_category_id : null,
                'name' => $this->evaluation_category_name,
            ],
            'answers_count' => (int) ($this->answers_count ?? 0),
            'total'         => $total,
            'max_total'     => $max,
            'ratio'         => $max > 0 ? round($total / $max, 4) : null,
            'submitted_at'  => $this->submitted_at,
        ];
    }
}
