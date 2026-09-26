<?php

namespace App\Repositories\Eloquents;

use App\Models\CourseRating;
use App\Repositories\Contracts\CourseRatingRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CourseRatingRepository extends BaseRepository implements CourseRatingRepositoryInterface
{
    public function __construct(CourseRating $model)
    {
        parent::__construct($model);
    }

    public function upsertForUser(int $courseId, int $userId, array $data): CourseRating
    {
        /** @var CourseRating */
        return $this->model->newQuery()->updateOrCreate(
            ['course_id' => $courseId, 'user_id' => $userId],
            $data,
        );
    }

}
