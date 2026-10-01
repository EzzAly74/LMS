<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Admin\AdminRoleService;
use Illuminate\Foundation\Http\FormRequest;

class AdminRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's section:roles gate decides who gets here; what they may
        // change is RoleAuthority's job (D-073).
        return $this->user() instanceof \App\Models\Admin;
    }

    public function rules(): array
    {
        return [
            'name_en'        => ['sometimes', 'string', 'max:191'],
            'name_ar'        => ['sometimes', 'nullable', 'string', 'max:191'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'color'          => ['sometimes', 'nullable', 'string', 'in:' . implode(',', AdminRoleService::COLORS)],
            'course_scope'   => ['sometimes', 'nullable', 'string', 'in:' . implode(',', AdminRoleService::SCOPES)],
            'permissions'    => ['sometimes', 'nullable', 'array', 'max:200'],
            'permissions.*'  => ['string', 'max:64', 'distinct'],
            // Older clients send view_keys (view only).
            'view_keys'      => ['sometimes', 'nullable', 'array', 'max:200'],
            'view_keys.*'    => ['string', 'max:64'],
        ];
    }
}
