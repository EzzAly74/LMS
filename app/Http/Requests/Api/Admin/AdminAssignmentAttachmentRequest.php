<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\AssignmentFileRules;
use Illuminate\Foundation\Http\FormRequest;

/** POST admin/assignments/{assignment}/questions/{question}/attachment */
class AdminAssignmentAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-assignments.
        return true;
    }

    public function rules(): array
    {
        return ['file' => AssignmentFileRules::rules()];
    }

    public function messages(): array
    {
        return AssignmentFileRules::messages('file');
    }
}
