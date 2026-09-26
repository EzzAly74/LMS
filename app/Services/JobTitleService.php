<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Repositories\Contracts\JobTitleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Support\CourseCompletion;
use App\Support\QualificationHolding;
use Illuminate\Support\Facades\DB;

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

        $this->attachQualificationBreakdown($jobTitle, $page->getCollection());

        return $page;
    }


    /**
     * Per-qualification progress for each learner on the page.
     *
     * The job-title detail table (Figma 2325:117118) labels a row
     * "N of M qualifications" and expands it into one sub-row per required
     * qualification, each reading "N of M Courses". The row-level course
     * counts alone cannot render either of those, so they are attached here.
     *
     * "Held" is App\Support\QualificationHolding (D-056): a direct grant, or
     * every linked course completed. M is every course linked to the
     * qualification, not only the ones this learner is enrolled in - counting
     * enrolled ones let "passed 1 of 3" read as "1 of 1 Courses - earned".
     *
     * A direct grant marks the qualification earned and 100%, but it does NOT
     * inflate `courses_completed`: the sub-row can read "0 of 3 Courses" while
     * the qualification still counts as held, which is exactly what an
     * externally-obtained certificate means.
     *
     * Two grouped queries for the whole page (linked courses per
     * qualification, passed ones per learner x qualification) plus the grants.
     */
    private function attachQualificationBreakdown(JobTitle $jobTitle, Collection $learners): void
    {
        $skills = $jobTitle->qualificationSkills;

        if ($learners->isEmpty()) {
            return;
        }

        $granted = $this->directGrants($learners, $skills);
        $linked  = collect();
        $passed  = collect();

        if ($skills->isNotEmpty()) {
            $linked = DB::table('course_qualification_skills')
                ->whereIn('qualification_skill_id', $skills->pluck('id'))
                ->groupBy('qualification_skill_id')
                ->selectRaw('qualification_skill_id AS q, COUNT(*) AS n')
                ->pluck('n', 'q');

            $passed = DB::table('course_qualification_skills as cqs')
                ->crossJoin('users')
                ->whereIn('users.id', $learners->pluck('id'))
                ->whereIn('cqs.qualification_skill_id', $skills->pluck('id'))
                ->whereRaw(CourseCompletion::existsSql('users.id', 'cqs.course_id'))
                ->groupBy('users.id', 'cqs.qualification_skill_id')
                ->get(['users.id as user_id', 'cqs.qualification_skill_id', DB::raw('COUNT(*) as passed')])
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('passed', 'qualification_skill_id'));
        }

        $locale = app()->getLocale();

        $learners->each(function ($user) use ($skills, $linked, $passed, $granted, $locale) {
            $mine   = $granted[$user->id] ?? [];
            $passes = $passed->get($user->id) ?? collect();

            $breakdown = $skills->map(function ($skill) use ($linked, $passes, $mine, $locale) {
                $total     = (int) ($linked[$skill->id] ?? 0);
                $completed = (int) ($passes[$skill->id] ?? 0);
                $direct    = in_array($skill->id, $mine, true);

                return [
                    'id'                => $skill->id,
                    'name'              => $skill->getTranslation('name', $locale),
                    'courses_total'     => $total,
                    'courses_completed' => $completed,
                    'percent'           => QualificationHolding::percent($direct, $total, $completed),
                    'granted_directly'  => $direct,
                    'earned'            => QualificationHolding::holds($direct, $total, $completed),
                ];
            })->values();

            $user->qualification_breakdown  = $breakdown->all();
            $user->qualifications_total     = $breakdown->count();
            // A qualification with no courses attached and no direct grant is
            // not counted - nothing has happened to earn it.
            $user->qualifications_completed = $breakdown->where('earned', true)->count();
        });
    }

    /**
     * Direct grants for the learners on this page, as [user_id => [skill_id, ...]].
     *
     * One query for the whole page, scoped to the qualifications this job
     * title actually requires - a learner may hold others, but they are not
     * part of THIS job title's compliance.
     *
     * @return array<int, list<int>>
     */
    private function directGrants(Collection $learners, Collection $skills): array
    {
        if ($learners->isEmpty() || $skills->isEmpty()) {
            return [];
        }

        return DB::table('user_qualification_skill')
            ->whereIn('user_id', $learners->pluck('id'))
            ->whereIn('qualification_skill_id', $skills->pluck('id'))
            ->get(['user_id', 'qualification_skill_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('qualification_skill_id')->map(fn ($v) => (int) $v)->all())
            ->all();
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
