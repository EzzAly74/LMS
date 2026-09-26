<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * An external training request for the Dashboard (Figma 2181:116177 row,
 * 2181:116391 review): the learner's fields plus who submitted it and who
 * decided it. The learner's name is resolved for the request locale.
 */
class AdminExternalTrainingResource extends ExternalTrainingResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return parent::toArray($request) + [
            'learner' => $user ? [
                'id'          => $user->id,
                'name'        => $user->getLocalizedName(),
                'employee_id' => $user->machine_code,
            ] : null,
            'decided_by' => $this->relationLoaded('decider') && $this->decider
                ? ['id' => $this->decider->id, 'name' => $this->decider->name]
                : null,
        ];
    }
}
