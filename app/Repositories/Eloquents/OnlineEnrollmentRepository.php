<?php

namespace App\Repositories\Eloquents;

use App\Models\UsersCourse;
use App\Repositories\Contracts\OnlineEnrollmentRepositoryInterface;
use App\Support\PersonName;
use Illuminate\Pagination\LengthAwarePaginator;

class OnlineEnrollmentRepository extends BaseRepository implements OnlineEnrollmentRepositoryInterface
{
    public function __construct(UsersCourse $model)
    {
        parent::__construct($model);
    }

    public function paginateForCourse(int $courseId, int $perPage, ?string $search): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with(['user:id,name,name_en,name_ar,email,machine_code,department_name'])
            ->where('course_id', $courseId)
            ->whereNull('group_id') // online enrollments have no group
            ->when($search, fn ($q) => $q->whereHas('user', fn ($u) => PersonName::whereUserMatches($u, 'users', $search, ['machine_code'])))
            ->latest()
            ->paginate($perPage);
    }

    public function getEnrolledUserIds(int $courseId): array
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->whereNull('group_id')
            ->pluck('user_id')
            ->all();
    }

    public function syncUsers(int $courseId, array $userIds): void
    {
        // Remove all online (no group) enrollments then re-attach
        $this->model->newQuery()
            ->where('course_id', $courseId)
            ->whereNull('group_id')
            ->delete();

        $this->attachUsers($courseId, $userIds);
    }

    public function attachUsers(int $courseId, array $userIds): void
    {
        $rows = array_map(fn ($id) => ['course_id' => $courseId, 'user_id' => $id], $userIds);
        $this->model->newQuery()->upsert($rows, ['course_id', 'user_id']);
    }

    public function detachUser(int $courseId, int $userId): int
    {
        return $this->model->newQuery()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->whereNull('group_id')
            ->delete();
    }
}
