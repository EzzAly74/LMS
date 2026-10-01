<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Admin\AdminRoleService;
use Illuminate\Foundation\Http\FormRequest;

class AdminRoleStoreRequest extends FormRequest
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
            'name_en'        => ['required', 'string', 'max:191'],
            'name_ar'        => ['required', 'string', 'max:191'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'color'          => ['nullable', 'string', 'in:' . implode(',', AdminRoleService::COLORS)],
            'course_scope'   => ['nullable', 'string', 'in:' . implode(',', AdminRoleService::SCOPES)],
            'permissions'    => ['nullable', 'array', 'max:200'],
            'permissions.*'  => ['string', 'max:64', 'distinct'],
            // Older clients send view_keys (view only).
            'view_keys'      => ['nullable', 'array', 'max:200'],
            'view_keys.*'    => ['string', 'max:64'],
        ];
    }
}
