<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Admin\AdminQualificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * POST / PUT admin/qualification-skills - the New Qualification modal
 * (Figma 2066:100876): both names, and the job titles and learners it is
 * assigned to, in one submit.
 *
 * `job_title_ids` and `learner_ids` are each optional: absent means "leave as
 * it is", an empty list means "none". On update they are the full new set.
 *
 * A name already used by another qualification, in either language and
 * ignoring case, is refused - the list, the pickers and the import all tell
 * qualifications apart by name, and two "Excel"s cannot be told apart.
 */
class AdminQualificationRequest extends FormRequest
{
    public const MAX_NAME = 255;

    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'array'],
            'name.en'         => ['required', 'string', 'max:'.self::MAX_NAME],
            'name.ar'         => ['required', 'string', 'max:'.self::MAX_NAME],
            'job_title_ids'   => ['sometimes', 'array', 'max:500'],
            'job_title_ids.*' => ['integer', 'distinct', 'exists:job_titles,id'],
            'learner_ids'     => ['sometimes', 'array', 'max:'.AdminQualificationService::MAX_LEARNERS],
            'learner_ids.*'   => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $self = $this->route('qualification_skill')?->id;
            foreach (['en', 'ar'] as $locale) {
                $name  = mb_strtolower(trim((string) $this->input("name.{$locale}")));
                $taken = DB::table('qualification_skills')
                    ->when($self, fn ($q) => $q->where('id', '!=', $self))
                    ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, '$.{$locale}'))) = ?", [$name])
                    ->exists();
                if ($taken) {
                    $v->errors()->add("name.{$locale}", __('messages.qualification_name_taken'));
                }
            }
        }];
    }

    /** The validated payload, names trimmed; assignment keys only when sent. */
    public function payload(): array
    {
        $d = $this->validated();
        $out = ['name' => ['en' => trim($d['name']['en']), 'ar' => trim($d['name']['ar'])]];

        foreach (['job_title_ids', 'learner_ids'] as $key) {
            if (array_key_exists($key, $d)) {
                $out[$key] = array_values(array_map('intval', $d[$key] ?? []));
            }
        }

        return $out;
    }
}
