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
                'label'     => "{$completed} of {$total}",
            ],

            /*
             * The table labels each row "N of M qualifications" (not courses)
             * and expands it into one sub-row per required qualification
             * reading "N of M Courses" (Figma 2325:117118). Both need the
             * per-qualification grid, which JobTitleService attaches in one
             * grouped query for the whole page.
             */
            'qualifications_completed' => (int) ($this->qualifications_completed ?? 0),
            'qualifications_total'     => (int) ($this->qualifications_total ?? 0),
            'qualification_breakdown'  => $this->qualification_breakdown ?? [],
            // True when the search matched a qualification, not this learner:
            // the breakdown above is narrowed to the matching qualifications.
            'qualification_match'      => (bool) ($this->qualification_match ?? false),

            // Whole percent, matching how JobTitleResource renders the card's
            // compliance bar. A learner with no relevant enrolments is 0, not
            // null, so the table never renders an empty cell for them.
            'completion_percent' => $total > 0
                ? (int) min(100, max(0, round($completed * 100 / $total)))
                : 0,

            // Kept for callers that only need the flat list; the per-learner
            // progress lives in `qualification_breakdown` above.
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
