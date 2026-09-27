<?php

namespace App\Http\Requests\Api;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET courses/{course}/enrollments - the Course Details
 * Learners tab (Figma 2266:129915) and the cohort learners modal (2276:133999).
 *
 * per_page is bounded (B-21). The cohort must belong to the route's course, so
 * another course's cohort id is a 422, never a silently empty (or foreign)
 * list. Status is the progress band the tab shows (D-059).
 */
class CourseLearnersRequest extends FormRequest
{
    public const STATUSES = ['not_started', 'in_progress', 'completed'];

    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-courses.
        return true;
    }

    public function rules(): array
    {
        /** @var Course $course */
        $course = $this->route('course');

        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'   => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'group_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('course_sections', 'id')->where('course_id', $course->id),
            ],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 20);
    }

    /** @return array{search?:string, status?:string, group_id?:int} */
    public function filters(): array
    {
        $f = [];
        if (($search = trim((string) $this->input('search', ''))) !== '') {
            $f['search'] = $search;
        }
        if ($this->filled('status')) {
            $f['status'] = (string) $this->input('status');
        }
        if ($this->filled('group_id')) {
            $f['group_id'] = $this->integer('group_id');
        }

        return $f;
    }
}
