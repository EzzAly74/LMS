<?php

namespace App\Http\Resources\Admin;

use App\Http\Traits\HasFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the job-title detail learners table (Figma 2325:117118).
 *
 * `courses_total` / `courses_completed` are projected as subquery columns by
 * JobTitleRepository::paginateLearners, so nothing here triggers a per-row
 * query.
 */
class JobTitleLearnerResource extends JsonResource
{
    use HasFile;

    public function toArray(Request $request): array
    {
        $total     = (int) ($this->courses_total ?? 0);
        $completed = (int) ($this->courses_completed ?? 0);

        return [
            'id'          => $this->id,
            'name'        => $this->getLocalizedName(),
            // The employee number the Figma table labels "ID". It is not a
            // secret, but note B-13: machine_code is the mobile identity, so
            // it stays behind admin auth and is not echoed to learners.
            'employee_id' => $this->machine_code,
            'image_url'   => $this->image ? $this->getFileUrl($this->image) : null,
            'department'  => $this->department_name,

            'courses' => [
                'completed' => $completed,
                'total'     => $total,
                // "N of M courses" in the design.
                'label'     => "{$completed} of {$total}",
            ],

            // Whole percent, matching how JobTitleResource renders the card's
            // compliance bar. A learner with no relevant enrolments is 0, not
            // null, so the table never renders an empty cell for them.
            'completion_percent' => $total > 0
                ? (int) min(100, max(0, round($completed * 100 / $total)))
                : 0,

            // The qualifications this job title requires. Assignment is via the
            // job title rather than per learner, so every row in a given table
            // carries the same list; it is included per row because the Figma
            // table renders it as a column.
            'qualifications' => $this->whenLoaded(
                'jobTitle',
                fn () => $this->jobTitle?->qualificationSkills
                    ->map(fn ($skill) => [
                        'id'   => $skill->id,
                        'name' => $skill->getTranslation('name', app()->getLocale()),
                    ])->values(),
            ),
        ];
    }
}
