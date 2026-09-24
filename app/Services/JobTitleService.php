<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Repositories\Contracts\JobTitleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Support\CourseCompletion;
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
     * One query for every learner on the page rather than one per learner:
     * the grid is (learner x qualification), so it is grouped in SQL and
     * pivoted in PHP.
     */
    private function attachQualificationBreakdown(JobTitle $jobTitle, Collection $learners): void
    {
        $skills = $jobTitle->qualificationSkills;

        if ($learners->isEmpty() || $skills->isEmpty()) {
            $learners->each(function ($user) use ($skills) {
                $user->qualification_breakdown = $skills->map(fn ($s) => [
                    'id'                => $s->id,
                    'name'              => $s->getTranslation('name', app()->getLocale()),
                    'courses_total'     => 0,
                    'courses_completed' => 0,
                    'percent'           => 0,
                ])->values()->all();
                $user->qualifications_total     = $skills->count();
                $user->qualifications_completed = 0;
            });

            return;
        }

        $rows = DB::table('users_courses')
            ->join(
                'course_qualification_skills as cqs',
                'users_courses.course_id',
                '=',
                'cqs.course_id',
            )
            ->whereIn('users_courses.user_id', $learners->pluck('id'))
            ->whereIn('cqs.qualification_skill_id', $skills->pluck('id'))
            ->groupBy('users_courses.user_id', 'cqs.qualification_skill_id')
            ->select([
                'users_courses.user_id',
                'cqs.qualification_skill_id',
                DB::raw('COUNT(DISTINCT users_courses.course_id) as courses_total'),
                // Same "completed" heuristic as list() and paginateLearners,
                // so the sub-rows add up to the row above them.
                DB::raw('COUNT(DISTINCT CASE WHEN '.CourseCompletion::existsSql('users_courses.user_id', 'users_courses.course_id').' THEN users_courses.course_id END) as courses_completed'),
            ])
            ->get()
            ->groupBy('user_id');

        $locale = app()->getLocale();

        $learners->each(function ($user) use ($skills, $rows, $locale) {
            $forUser = ($rows->get($user->id) ?? collect())->keyBy('qualification_skill_id');

            $breakdown = $skills->map(function ($skill) use ($forUser, $locale) {
                $row       = $forUser->get($skill->id);
                $total     = (int) ($row->courses_total ?? 0);
                $completed = (int) ($row->courses_completed ?? 0);

                return [
                    'id'                => $skill->id,
                    'name'              => $skill->getTranslation('name', $locale),
                    'courses_total'     => $total,
                    'courses_completed' => $completed,
                    'percent'           => $total > 0
                        ? (int) min(100, max(0, round($completed * 100 / $total)))
                        : 0,
                ];
            })->values();

            $user->qualification_breakdown  = $breakdown->all();
            $user->qualifications_total     = $breakdown->count();
            // A qualification counts as earned only when every course granting
            // it is complete, and a qualification with no courses attached is
            // not silently counted as earned.
            $user->qualifications_completed = $breakdown
                ->filter(fn ($q) => $q['courses_total'] > 0 && $q['courses_completed'] >= $q['courses_total'])
                ->count();
        });
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
