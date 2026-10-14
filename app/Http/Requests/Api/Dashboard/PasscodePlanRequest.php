<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET dashboard/passcode/plan: which session "Generate Passcode" would open
 * for a cohort (D-105). Who may ask is the controller's instructor check.
 */
final class PasscodePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer'],
            'cohort_id' => ['required', 'integer'],
        ];
    }
}
