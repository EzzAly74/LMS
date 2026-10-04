<?php

namespace App\Observers;

use App\Models\CourseSection;
use App\Services\Learner\CohortInterestNotifier;
use Illuminate\Support\Facades\DB;

/**
 * A saved cohort may be the one learners are waiting for (NEW2B-5780). The
 * check runs after the transaction commits and after the response is sent,
 * so saving a cohort is never held up by email.
 */
class CourseSectionObserver
{
    public function saved(CourseSection $section): void
    {
        $courseId = (int) $section->course_id;

        DB::afterCommit(static function () use ($courseId) {
            \Illuminate\Support\defer(static fn () => app(CohortInterestNotifier::class)->notifyForCourse($courseId))
                ->always();
        });
    }
}
