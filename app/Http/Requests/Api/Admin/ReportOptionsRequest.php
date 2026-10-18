<?php

namespace App\Http\Requests\Api\Admin;

use App\Reports\ReportContext;
use App\Reports\ReportOptions;
use App\Services\Admin\CourseScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET admin/reports/options/{source}: the filter choices (D-112). The source
 * is allow-listed by the route; the search is bounded, and `course_ids`
 * narrows cohorts, quizzes and assignments (checked against the course
 * scope by AdminSectionMiddleware, D-074).
 */
class ReportOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'course_ids' => ['sometimes', 'array', 'max:100'],
            'course_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function source(): string
    {
        return (string) $this->route('source');
    }

    public function search(): ?string
    {
        $term = trim((string) $this->validated('search', ''));

        return $term === '' ? null : $term;
    }

    /** @return list<int> */
    public function courseIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('course_ids', []))));
    }

    public function context(): ReportContext
    {
        return new ReportContext([], app()->getLocale(), app(CourseScope::class)->courseIds($this->user()));
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return ReportOptions::SOURCES;
    }
}
