<?php

namespace App\Http\Resources;

use App\Models\UsersCourse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One enrolment row of the Course Details Learners tab (Figma 2266:129915).
 *
 * `progress` is the share of the course's lectures the learner completed, and
 * `status` its band - the same definition as the header's "N Active" (D-059).
 * `active` is the account status the avatar dot shows (Q-054).
 *
 * @mixin UsersCourse
 */
class CourseLearnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $progress = max(0, min(100, (int) ($this->progress_percent ?? 0)));
        $user     = $this->user;
        $cohort   = $this->group;

        return [
            'id'          => $this->id,
            'user'        => $user ? [
                'id'          => $user->id,
                'name'        => $user->getLocalizedName(),
                'employee_id' => $user->machine_code,
                'active'      => $user->status === 'active',
            ] : null,
            'cohort'      => $cohort ? [
                'id'   => $cohort->id,
                'name' => $cohort->getTranslation('name', app()->getLocale()),
            ] : null,
            'progress'    => $progress,
            'status'      => match (true) {
                $progress >= 100 => 'completed',
                $progress > 0    => 'in_progress',
                default          => 'not_started',
            },
            'enrolled_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
