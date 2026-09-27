<?php

namespace App\Repositories\Contracts;

use App\Models\Course;
use App\Models\UsersCourse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserEnrollmentRepositoryInterface
{
    /** @param  array{search?:string, status?:string, group_id?:int}  $filters */
    public function paginateForCourse(Course $course, int $perPage, array $filters = []): LengthAwarePaginator;
    public function countInProgress(Course $course): int;
    public function syncUsers(Course $course, array $userIds, ?int $groupId): void;
    public function delete(UsersCourse $enrollment): void;
}
