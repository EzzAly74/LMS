<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings'   => 'required|array',
            'settings.*' => 'nullable|string|max:5000',
            // Platform Config numbers (NEW2B-6055, NEW2B-6059): whole numbers in
            // range, so a cohort always has at least one seat and nothing is
            // negative.
            'settings.default_cohort_size'               => 'sometimes|required|integer|min:1|max:1000',
            'settings.academy_default_close_offset_days' => 'sometimes|required|integer|min:0|max:365',
            'settings.passcode_reset_seconds'            => 'sometimes|required|integer|min:1|max:86400',
            'settings.min_passing_attendance'            => 'sometimes|required|integer|min:0|max:100',
            'settings.min_passing_score'                 => 'sometimes|required|integer|min:0|max:100',
            'settings.course_attendance_enabled'         => 'sometimes|required|in:0,1',
            'settings.certificate_award_basis'           => 'sometimes|required|in:attendance,score,both',
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'settings.default_cohort_size'               => __('validation.attributes.default_cohort_size'),
            'settings.academy_default_close_offset_days' => __('validation.attributes.academy_default_close_offset_days'),
            'settings.passcode_reset_seconds'            => __('validation.attributes.passcode_reset_seconds'),
            'settings.min_passing_attendance'            => __('validation.attributes.min_passing_attendance'),
            'settings.min_passing_score'                 => __('validation.attributes.min_passing_score'),
        ];
    }
}
