<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST admin/external-training/{id}/reject - Figma 2209:90462: a reason is required and shown to the learner. */
class AdminExternalTrainingRejectRequest extends FormRequest
{
    public const MAX_REASON = 1000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:'.self::MAX_REASON]];
    }

    public function messages(): array
    {
        return ['reason.required' => __('messages.external_training_reason_required')];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
