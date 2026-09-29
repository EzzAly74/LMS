<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The multi-choice filters of GET courses - the All Courses "Filter" modal
 * (Figma 2430:135164 / 2430:134497): courses, categories, instructors, status
 * and evaluation band, each several at once (OR within a field, AND across).
 *
 * Only these keys are validated here. The older single-value params (search,
 * category_id, status, active, course_type, per_page) stay as the controller
 * read them, so the many Dashboard dropdowns that call this endpoint keep
 * working unchanged. Each list is bounded so a query string cannot build an
 * unbounded IN clause.
 */
class CourseIndexRequest extends FormRequest
{
    public const STATUSES = ['active', 'upcoming', 'inactive'];

    /** Bands of the /5 evaluation score; `none` = nobody has evaluated yet. */
    public const EVALUATION_BANDS = ['high', 'mid', 'low', 'none'];

    private const MAX_IDS = 100;

    public function authorize(): bool
    {
        // Route middleware enforces auth.user.
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'              => ['sometimes', 'array', 'max:'.self::MAX_IDS],
            'ids.*'            => ['integer', 'min:1'],
            'category_ids'     => ['sometimes', 'array', 'max:'.self::MAX_IDS],
            'category_ids.*'   => ['integer', 'min:1'],
            'instructor_ids'   => ['sometimes', 'array', 'max:'.self::MAX_IDS],
            'instructor_ids.*' => ['integer', 'min:1'],
            'statuses'         => ['sometimes', 'array', 'max:'.count(self::STATUSES)],
            'statuses.*'       => [Rule::in(self::STATUSES)],
            'evaluation'       => ['sometimes', 'array', 'max:'.count(self::EVALUATION_BANDS)],
            'evaluation.*'     => [Rule::in(self::EVALUATION_BANDS)],
        ];
    }

    /**
     * @return array{ids?:list<int>, category_ids?:list<int>, instructor_ids?:list<int>, statuses?:list<string>, evaluation?:list<string>}
     */
    public function filters(): array
    {
        $f = [];
        foreach (['ids', 'category_ids', 'instructor_ids'] as $key) {
            $ids = array_values(array_unique(array_map('intval', (array) $this->validated($key, []))));
            if ($ids !== []) {
                $f[$key] = $ids;
            }
        }
        foreach (['statuses', 'evaluation'] as $key) {
            $values = array_values(array_unique(array_map('strval', (array) $this->validated($key, []))));
            if ($values !== []) {
                $f[$key] = $values;
            }
        }

        return $f;
    }
}
