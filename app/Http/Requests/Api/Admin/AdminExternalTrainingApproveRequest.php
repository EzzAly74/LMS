<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/external-training/{id}/approve. The qualification is optional -
 * approval grants one only when the admin picks it (D-057) - and so is the
 * internal course the request is recorded against (Q-042).
 */
class AdminExternalTrainingApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qualification_skill_id' => ['nullable', 'integer', 'exists:qualification_skills,id'],
            'course_id'              => ['nullable', 'integer', 'exists:courses,id'],
        ];
    }

    public function qualificationId(): ?int
    {
        return $this->filled('qualification_skill_id') ? (int) $this->input('qualification_skill_id') : null;
    }

    public function courseId(): ?int
    {
        return $this->filled('course_id') ? (int) $this->input('course_id') : null;
    }
}
