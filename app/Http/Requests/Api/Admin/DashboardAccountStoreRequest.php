<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\Admin;
use App\Rules\FullName;
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

    /** Photo types and size for every Dashboard account (NEW2B-6165, NEW2B-6167). */
    public const IMAGE_TYPES = 'png,jpg,jpeg,webp,gif';

    public const IMAGE_MAX_KB = 3072;

    /** The password rule for every Dashboard account (D-075). */
    public static function passwordRule(): Password
    {
        return Password::min(8)->letters()->mixedCase()->numbers();
    }

    public function rules(): array
    {
        return [
            'name_en'               => ['required', 'string', new FullName()],
            'name_ar'               => ['required', 'string', new FullName()],
            'email'                 => ['required', 'email', 'max:255', Rule::unique('admins', 'email')],
            // Dashboard roles only: `learner` is a website identity.
            'role'                  => ['required', 'string', Rule::exists('roles', 'name')
                ->where(fn ($q) => $q->where('guard_name', 'admin')->where('name', '!=', 'learner'))],
            'password'              => ['required', 'string', 'confirmed', self::passwordRule()],
            'password_confirmation' => ['required', 'string'],
            // Shown under the instructor's name on the Website (NEW2B-5926).
            'title_en'              => ['nullable', 'string', 'max:150'],
            'title_ar'              => ['nullable', 'string', 'max:150'],
            'brief_en'              => ['nullable', 'string', 'max:2000'],
            'brief_ar'              => ['nullable', 'string', 'max:2000'],
            // GIF too: the photo picker offers it (NEW2B-6165). Never SVG (D-044).
            'image'                 => ['nullable', 'image', 'mimes:'.self::IMAGE_TYPES, 'max:'.self::IMAGE_MAX_KB],
        ];
    }

    /**
     * Messages that say what to do (NEW2B-6162, NEW2B-6167), shared with the
     * update request.
     *
     * @return array<string,string>
     */
    public static function accountMessages(): array
    {
        return [
            'email.unique' => __('validation.account_email_taken'),
            'image.mimes'  => __('validation.account_image.mimes'),
            'image.image'  => __('validation.account_image.mimes'),
            'image.max'    => __('validation.account_image.max'),
        ];
    }

    public function messages(): array
    {
        return self::accountMessages();
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
