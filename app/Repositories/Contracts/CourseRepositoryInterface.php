<?php

namespace App\Repositories\Contracts;

use App\Models\Course;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CourseRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * @param  array{ids?:list<int>, category_ids?:list<int>, instructor_ids?:list<int>, statuses?:list<string>}  $filters
     *         the All Courses filter modal (OR within a key, AND across keys)
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<Course>): void)|null  $scope  a further constraint (the evaluation bands)
     */
    public function paginateWithFilters(
        int     $perPage,
        ?string $search,
        ?int    $categoryId,
        ?bool   $active,
        ?string $courseType,
        ?string $status = null,
        array   $filters = [],
        ?\Closure $scope = null,
    ): LengthAwarePaginator;

    /**
     * @return array{all: int, active: int, inactive: int, pending: int, upcoming: int}
     */
    public function tabCounts(): array;

    public function allActive(): Collection;

    public function findWithRelations(int $id): Course;

    public function findWithBasicRelations(int $id): Course;

    public function activePluckedTitles(): Collection;
}
