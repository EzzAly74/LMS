<?php

namespace App\Http\Resources\Admin;

use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "View Learners scores" (Figma 2017:52260).
 *
 * The row is keyed by (learner, course, template) because there is no
 * submission id - the UI needs all three to open the detail.
 *
 * `score` is the /5 score and `passed` its verdict (D-054). `total`,
 * `max_total` and `ratio` are kept from the B3 contract.
 */
class AdminEvaluationScoreRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (float) ($this->total ?? 0);
        $max   = (float) ($this->max_total ?? 0);
        $score = $this->score;

        return [
            'learner' => [
                'id'          => (int) $this->user_id,
                'name'        => $this->learner_name,
                'employee_id' => $this->user_machine_code,
                'department'  => $this->user_department,
            ],
            'course' => [
                'id'   => (int) $this->course_id,
                'name' => $this->course_name,
            ],
            'template' => [
                'id'         => $this->evaluation_category_id !== null ? (int) $this->evaluation_category_id : null,
                'name'       => $this->template_name,
                'created_at' => $this->template_created_at,
            ],
            'answers_count' => (int) ($this->answers_count ?? 0),
            'total'         => $total,
            'max_total'     => $max,
            'ratio'         => $max > 0 ? round($total / $max, 4) : null,
            'score'         => $score,
            'passed'        => app(AdminEvaluationReportService::class)->passed($score),
            'submitted_at'  => $this->submitted_at,
        ];
    }
}
