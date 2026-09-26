<?php

namespace App\Services;

use App\Models\QualificationSkill;
use App\Repositories\Contracts\QualificationSkillRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class QualificationSkillService
{
    public function __construct(
        private readonly QualificationSkillRepositoryInterface $repository,
    ) {}

    public function list(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        return $this->repository->paginateWithFilters($perPage, $search);
    }

    public function allForSelect(): Collection
    {
        return $this->repository->allForSelect();
    }

    public function findOrFail(int $id): QualificationSkill
    {
        return $this->repository->findOrFail($id);
    }
}
