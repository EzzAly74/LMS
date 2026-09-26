<?php

namespace App\Repositories\Contracts;

use App\Models\CourseRating;

interface CourseRatingRepositoryInterface extends BaseRepositoryInterface
{
    public function upsertForUser(int $courseId, int $userId, array $data): CourseRating;
}
