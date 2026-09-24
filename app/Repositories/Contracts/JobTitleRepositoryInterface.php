<?php

namespace App\Repositories\Contracts;

use App\Models\JobTitle;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface JobTitleRepositoryInterface extends BaseRepositoryInterface
{
    public function list(int $perPage, ?string $search): LengthAwarePaginator;

    /** Learners holding this job title, with progress toward its qualifications. */
    public function paginateLearners(
        JobTitle $jobTitle,
        int $perPage,
        ?string $search = null,
        string $sort = 'name',
        string $dir = 'asc',
    ): LengthAwarePaginator;

    public function allForSelect(): Collection;

    public function syncQualifications(JobTitle $jobTitle, array $qualIds): void;
}
