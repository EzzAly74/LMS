<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobTitleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->getLocalizedName(),
            'qualifications_count' => $this->whenCounted('qualificationSkills'),
            /**
             * Read the aliased column directly: the repository projects
             * `withCount(['users as employees_count'])`, so the value
             * lands on `$this->employees_count` rather than the default
             * `users_count` attribute `whenCounted('users')` expects.
             */
            'employees_count'      => (int) ($this->employees_count ?? 0),
            'learners_count'       => (int) ($this->learners_count ?? 0),
            'compliance_percent'   => $this->resolveCompliancePercent(),
            'qualifications'       => $this->whenLoaded(
                'qualificationSkills',
                fn () => $this->qualificationSkills->map(fn ($skill) => [
                    'id'   => $skill->id,
                    'name' => $skill->getTranslation('name', app()->getLocale()),
                ]),
            ),
        ];
    }

    /**
     * Compute the job-title compliance percentage from the
     * repository-provided counts.
     *
     *   compliance = completed (learner, required-qual) pairs
     *              ÷ (learners × required-qualifications)
     *
     * Falls back to 0 whenever either denominator is zero — i.e. a job
     * title with no enrolled learners or no required qualifications has
     * no meaningful compliance score and renders an empty bar.
     */
    private function resolveCompliancePercent(): int
    {
        $learners = (int) ($this->learners_count ?? 0);

        /**
         * `withCount('qualificationSkills')` lands on the model as
         * `qualification_skills_count`, not `qualifications_count`.
         *
         * This read used `$this->qualifications_count`, which is always NULL,
         * so `$quals` was always 0 and the early return below fired every
         * time: **compliance_percent was hard 0 on every card since the
         * feature shipped**, and the Figma compliance bar never showed a real
         * value. Found in Stage B while writing the test that was supposed to
         * merely confirm these metrics already existed. Logged as B-103.
         *
         * The old key is kept as a fallback in case a caller projects that
         * alias explicitly.
         */
        $quals     = (int) ($this->qualification_skills_count ?? $this->qualifications_count ?? 0);
        $completed = (int) ($this->completed_qualifications_count ?? 0);

        if ($learners <= 0 || $quals <= 0) {
            return 0;
        }

        return (int) min(100, max(0, round($completed * 100 / ($learners * $quals))));
    }
}
