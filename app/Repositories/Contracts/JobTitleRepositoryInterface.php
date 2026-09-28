<?php

namespace App\Repositories\Contracts;

use App\Models\JobTitle;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface JobTitleRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * @param  list<int>  $qualificationIds  job titles requiring any of these
     * @param  bool  $learnerSearch  also match the names / IDs of the job title's employees (admin index only)
     */
    public function list(
        int $perPage,
        ?string $search,
        array $qualificationIds = [],
        ?int $learnerId = null,
        bool $learnerSearch = false,
    ): LengthAwarePaginator;

    /** Learners holding this job title, with progress toward its qualifications. */
    public function paginateLearners(
        JobTitle $jobTitle,
        int $perPage,
        ?string $search = null,
        string $sort = 'name',
        string $dir = 'asc',
        bool $searchMatchesQualification = false,
    ): LengthAwarePaginator;

    /** Learners holding any job title, for the index Filter modal. */
    public function learnerOptions(?string $search, int $limit): Collection;

    public function allForSelect(): Collection;

    public function syncQualifications(JobTitle $jobTitle, array $qualIds): void;
}
