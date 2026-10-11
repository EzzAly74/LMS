<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\Admin;
use App\Rules\FullName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Changing a Dashboard account (D-075): every field optional. */
class DashboardAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Admin;
    }

    public function rules(): array
    {
        $id = (int) $this->route('id');

        return [
            'name_en'               => ['sometimes', 'nullable', 'string', new FullName()],
            'name_ar'               => ['sometimes', 'nullable', 'string', new FullName()],
            'email'                 => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($id)],
            'role'                  => ['sometimes', 'nullable', 'string', Rule::exists('roles', 'name')
                ->where(fn ($q) => $q->where('guard_name', 'admin')->where('name', '!=', 'learner'))],
            // Blank keeps the current password.
            'password'              => ['sometimes', 'nullable', 'string', 'confirmed', DashboardAccountStoreRequest::passwordRule()],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
            'status'                => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'deactivated'])],
            'title_en'              => ['sometimes', 'nullable', 'string', 'max:150'],
            'title_ar'              => ['sometimes', 'nullable', 'string', 'max:150'],
            'brief_en'              => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brief_ar'              => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image'                 => ['sometimes', 'nullable', 'image', 'mimes:'.DashboardAccountStoreRequest::IMAGE_TYPES, 'max:'.DashboardAccountStoreRequest::IMAGE_MAX_KB],
            // Removes the current photo (NEW2B-6160); ignored when a new one is sent.
            'remove_image'          => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return DashboardAccountStoreRequest::accountMessages();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        foreach (['name_en', 'name_ar'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) preg_replace('/\s+/u', ' ', $this->input($field)))]);
            }
        }
    }
}
