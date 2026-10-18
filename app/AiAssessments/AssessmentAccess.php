<?php

namespace App\AiAssessments;

use App\Models\Admin;
use App\Models\AiAssessment;
use App\Services\Admin\CourseScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees an assessment (H2): its author, and super admins. On top of that,
 * course scope (D-074): an account limited to its own courses never sees an
 * assessment tagged to another course, even its own after the course was
 * taken away from it.
 *
 * Anything outside this answers 404, not 403, so ids of other trainers'
 * assessments cannot be probed (IDOR).
 */
final class AssessmentAccess
{
    public function __construct(private readonly CourseScope $scope) {}

    public function query(Admin $admin): Builder
    {
        $query = AiAssessment::query();

        if (! $admin->isSuperAdmin()) {
            $query->where('ai_assessments.admin_id', $admin->id);
        }

        $courseIds = $this->scope->courseIds($admin);
        if ($courseIds !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('ai_assessments.course_id')
                ->orWhereIn('ai_assessments.course_id', $courseIds));
        }

        return $query;
    }

    public function find(Admin $admin, int|string $id): AiAssessment
    {
        return $this->query($admin)->whereKey((int) $id)->firstOrFail();
    }
}
