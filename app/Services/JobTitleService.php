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
     *
     * A learner holds a qualification by EITHER route (D-045):
     *   (a) a direct grant in `user_qualification_skill` - the manual
     *       override for an external certificate or prior learning, or
     *   (b) completing every course that grants it.
     *
     * A direct grant sets `granted_directly` and marks the qualification
     * earned, but it deliberately does NOT inflate `courses_completed`.
     * The course counts stay a truthful record of what was actually
     * studied, so the sub-row can read "0 of 3 Courses" while the
     * qualification still counts as held - which is exactly what an
     * externally-obtained certificate means.
     */
    private function attachQualificationBreakdown(JobTitle $jobTitle, Collection $learners): void
    {
        $skills = $jobTitle->qualificationSkills;

        if ($learners->isEmpty() || $skills->isEmpty()) {
            $granted = $this->directGrants($learners, $skills);

            $learners->each(function ($user) use ($skills, $granted) {
                $mine = $granted[$user->id] ?? [];

                $user->qualification_breakdown = $skills->map(fn ($s) => [
                    'id'                => $s->id,
                    'name'              => $s->getTranslation('name', app()->getLocale()),
                    'courses_total'     => 0,
                    'courses_completed' => 0,
                    'percent'           => in_array($s->id, $mine, true) ? 100 : 0,
                    'granted_directly'  => in_array($s->id, $mine, true),
                    'earned'            => in_array($s->id, $mine, true),
                ])->values()->all();

                $user->qualifications_total     = $skills->count();
                $user->qualifications_completed = count(array_intersect($mine, $skills->pluck('id')->all()));
            });

            return;
        }

        $granted = $this->directGrants($learners, $skills);

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

        $learners->each(function ($user) use ($skills, $rows, $locale, $granted) {
            $forUser = ($rows->get($user->id) ?? collect())->keyBy('qualification_skill_id');
            $mine    = $granted[$user->id] ?? [];

            $breakdown = $skills->map(function ($skill) use ($forUser, $locale, $mine) {
                $row       = $forUser->get($skill->id);
                $total     = (int) ($row->courses_total ?? 0);
                $completed = (int) ($row->courses_completed ?? 0);
                $direct    = in_array($skill->id, $mine, true);

                // Earned by study: every course granting it is done, and
                // there was at least one course to do.
                $byStudy = $total > 0 && $completed >= $total;

                return [
                    'id'                => $skill->id,
                    'name'              => $skill->getTranslation('name', $locale),
                    'courses_total'     => $total,
                    'courses_completed' => $completed,
                    'percent'           => $direct
                        ? 100
                        : ($total > 0 ? (int) min(100, max(0, round($completed * 100 / $total))) : 0),
                    'granted_directly'  => $direct,
                    'earned'            => $direct || $byStudy,
                ];
            })->values();

            $user->qualification_breakdown  = $breakdown->all();
            $user->qualifications_total     = $breakdown->count();
            // Earned by either route (D-045). A qualification with no
            // courses attached and no direct grant is still not counted -
            // nothing has happened to earn it.
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
