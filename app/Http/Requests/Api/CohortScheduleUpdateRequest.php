<?php

namespace App\Http\Requests\Api;

use App\Models\CourseSection;
use Illuminate\Support\Facades\DB;

/**
 * POST courses/{course}/sections/{section}/scheduled - Edit Cohort, the same
 * dialog as New Cohort: both names, the capacity, and optionally the schedule
 * again (checked like the New Cohort upload). The capacity cannot drop below
 * the learners already enrolled in the cohort.
 */
class CohortScheduleUpdateRequest extends CohortWithScheduleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['schedule'][0] = 'nullable';

        /** @var CourseSection $section */
        $section  = $this->route('section');
        $enrolled = DB::table('users_courses')->where('group_id', $section->id)->count();
        if ($enrolled > 0) {
            $rules['capacity'] = [...array_filter($rules['capacity'], static fn ($r) => $r !== 'min:1'), 'min:'.$enrolled];
        }

        return $rules;
    }

    /** As on create, but a capacity left out keeps the cohort's own. */
    public function cohort(): array
    {
        $d = $this->validated();
        /** @var CourseSection $section */
        $section = $this->route('section');

        return [
            'name'       => ['en' => trim($d['name']['en']), 'ar' => trim($d['name']['ar'])],
            'capacity'   => (int) ($d['capacity'] ?? $section->capacity ?? 30),
            // Left out = unchanged.
            'open_early' => $this->has('open_for_enrollment') ? $this->boolean('open_for_enrollment') : null,
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'capacity.min' => __('messages.cohort_capacity_below_enrolled', ['count' => ':min']),
        ];
    }
}
