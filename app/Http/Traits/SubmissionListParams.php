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
     * Bounded page size (B-21). The cap is 200, not 100: the Quizzes and
     * Assignments list pages still read up to 200 rows to build their learner
     * filter.
     */
    protected function submissionsPerPage(Request $request): int
    {
        return max(1, min(200, (int) $request->get('per_page', 20)));
    }
}
