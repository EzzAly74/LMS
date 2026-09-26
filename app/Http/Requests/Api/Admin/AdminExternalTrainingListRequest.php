<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\ExternalTrainingRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET admin/external-training - Figma 2181:116177. per_page bounded (B-21). */
class AdminExternalTrainingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-external-training.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'     => ['sometimes', 'nullable', 'string', 'max:191'],
            'statuses'   => ['sometimes', 'array', 'max:3'],
            'statuses.*' => [Rule::in(ExternalTrainingRequest::VISIBLE)],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }

    /** @return array{statuses?:list<string>, search?:string} */
    public function filters(): array
    {
        $f = [];
        $statuses = array_values(array_unique((array) $this->input('statuses', [])));
        if ($statuses !== []) {
            $f['statuses'] = $statuses;
        }
        $search = trim((string) $this->input('search', ''));
        if ($search !== '') {
            $f['search'] = $search;
        }

        return $f;
    }
}
