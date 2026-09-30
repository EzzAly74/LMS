<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared query handling for GET admin/quizzes/submissions and
 * admin/assignments/submissions.
 */
trait SubmissionListParams
{
    /**
     * Course Details cohort filter (Figma 2295:52815): the learner's cohort,
     * which needs `course_id` and must belong to that course, so another
     * course's cohort is a 422 rather than a silently empty list.
     */
    protected function validateCohortFilter(Request $request): void
    {
        $request->validate([
            'course_id'  => ['required_with:section_id', 'nullable', 'integer'],
            'section_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('course_sections', 'id')->where('course_id', $request->integer('course_id')),
            ],
        ]);
    }

    /**
     * `course_id` for the submission filter options: one real course (Course
     * Details tabs), or none for every course (the Quizzes and Assignments
     * list pages). The options are bounded either way (500 each).
     */
    protected function validatedCourseId(Request $request): ?int
    {
        $id = $request->validate(['course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id']])['course_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Bounded page size (B-21). The cap is 200, not 100: the Quizzes and
     * Assignments lists' "Show All" loads one page of 200.
     */
    protected function submissionsPerPage(Request $request): int
    {
        return max(1, min(200, (int) $request->get('per_page', 20)));
    }
}
