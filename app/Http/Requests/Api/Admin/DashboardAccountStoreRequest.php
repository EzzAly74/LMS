<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A new Dashboard account (D-075). The route's section:users gate decides who
 * gets here; which role they may give is RoleAuthority's call.
 */
class DashboardAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Admin;
    }

    /** The password rule for every Dashboard account (D-075). */
    public static function passwordRule(): Password
    {
        return Password::min(8)->letters()->mixedCase()->numbers();
    }

    public function rules(): array
    {
        return [
            'name_en'               => ['required', 'string', 'max:255'],
            'name_ar'               => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'max:255', Rule::unique('admins', 'email')],
            // Dashboard roles only: `learner` is a website identity.
            'role'                  => ['required', 'string', Rule::exists('roles', 'name')
                ->where(fn ($q) => $q->where('guard_name', 'admin')->where('name', '!=', 'learner'))],
            'password'              => ['required', 'string', 'confirmed', self::passwordRule()],
            'password_confirmation' => ['required', 'string'],
            'brief_en'              => ['nullable', 'string', 'max:2000'],
            'brief_ar'              => ['nullable', 'string', 'max:2000'],
            'image'                 => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
