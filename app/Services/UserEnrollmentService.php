<?php

namespace App\Services;

use App\Models\Course;
use App\Models\UsersCourse;
use App\Repositories\Contracts\UserEnrollmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserEnrollmentService
{
    public function __construct(
        private readonly UserEnrollmentRepositoryInterface $repo
    ) {}

    /** @param  array{search?:string, status?:string, group_id?:int}  $filters */
    public function paginate(Course $course, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->repo->paginateForCourse($course, $perPage, $filters);
    }

    public function countInProgress(Course $course): int
    {
        return $this->repo->countInProgress($course);
    }

    public function enroll(Course $course, array $userIds, ?int $groupId): int
    {
        $this->repo->syncUsers($course, $userIds, $groupId);
        return count($userIds);
    }

    public function remove(UsersCourse $enrollment): void
    {
        $this->repo->delete($enrollment);
    }
}
