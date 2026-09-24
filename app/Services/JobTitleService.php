<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Repositories\Contracts\JobTitleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class JobTitleService
{
    public function __construct(
        private readonly JobTitleRepositoryInterface $repository,
    ) {}

    public function list(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        return $this->repository->list($perPage, $search);
    }

    /**
     * Learners holding this job title (Figma 2325:117118).
     *
     * Eager-loads the job title with its qualification skills so the resource
     * can render the qualifications column without a query per row.
     */
    public function learnersFor(
        JobTitle $jobTitle,
        int $perPage = 20,
        ?string $search = null,
        string $sort = 'name',
        string $dir = 'asc',
    ): LengthAwarePaginator {
        $page = $this->repository->paginateLearners($jobTitle, $perPage, $search, $sort, $dir);

        $page->getCollection()->each(
            fn ($user) => $user->setRelation('jobTitle', $jobTitle),
        );

        return $page;
    }

    public function allForSelect(): Collection
    {
        return $this->repository->allForSelect();
    }

    public function syncQualifications(JobTitle $jobTitle, array $qualIds): JobTitle
    {
        $this->repository->syncQualifications($jobTitle, $qualIds);

        return $jobTitle->load('qualificationSkills');
    }
}
