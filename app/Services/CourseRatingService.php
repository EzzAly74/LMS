<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseRating;
use App\Repositories\Contracts\CourseRatingRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CourseRatingService
{
    public function __construct(
        private readonly CourseRatingRepositoryInterface $ratingRepository,
    ) {}

    public function submitRating(Course $course, int $userId, array $data): CourseRating
    {
        return $this->ratingRepository->upsertForUser($course->id, $userId, $data);
    }

}
