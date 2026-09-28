<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Repositories\Contracts\JobTitleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Support\CourseCompletion;
use App\Support\LocalizedJson;
use App\Support\QualificationHolding;
use Illuminate\Support\Facades\DB;

class JobTitleService
{
    /** Learner options returned to the index Filter modal per search. */
    public const LEARNER_OPTION_LIMIT = 20;

    public function __construct(
        private readonly JobTitleRepositoryInterface $repository,
    ) {}

    /**
     * @param  list<int>  $qualificationIds
     */
    public function list(
        int $perPage = 15,
        ?string $search = null,
        array $qualificationIds = [],
        ?int $learnerId = null,
        bool $learnerSearch = false,
    ): LengthAwarePaginator {
        return $this->repository->list($perPage, $search, $qualificationIds, $learnerId, $learnerSearch);
    }

    /**
     * Learners holding any job title, as Filter-modal options.
     *
     * @return list<array{id:int, name:string, employee_id:?string}>
     */
    public function learnerOptions(?string $search): array
    {
        return $this->repository->learnerOptions($search, self::LEARNER_OPTION_LIMIT)
            ->map(fn ($u) => [
                'id'          => (int) $u->id,
                'name'        => $u->getLocalizedName(),
                'employee_id' => $u->machine_code,
            ])
            ->values()
            ->all();
    }

    /**
     * Learners holding this job title (Figma 2325:117118 / 2459:137558).
     *
     * "Search by learner or qualification": a term matching one of the job
     * title's required qualifications returns every learner (they all share
     * it) with each row narrowed to the matching qualifications and flagged
     * `qualification_match`, so the page can open those rows. A learner whose
     * own name or ID matches keeps the full breakdown.
     */
    public function learnersFor(
        JobTitle $jobTitle,
        int $perPage = 20,
        ?string $search = null,
        string $sort = 'name',
        string $dir = 'asc',
    ): LengthAwarePaginator {
        $search = $search !== null ? trim($search) : null;
        $matchedSkillIds = $search ? $this->matchingSkillIds($jobTitle, $search) : [];

        $page = $this->repository->paginateLearners(
            $jobTitle, $perPage, $search, $sort, $dir,
            searchMatchesQualification: $matchedSkillIds !== [],
        );

        $page->getCollection()->each(
            fn ($user) => $user->setRelation('jobTitle', $jobTitle),
        );

        $this->attachQualificationBreakdown($jobTitle, $page->getCollection(), $search, $matchedSkillIds);

        return $page;
    }

    /**
     * Required qualifications whose name contains the term in either language.
     * Matched in PHP: a job title requires a handful, already loaded, and the
     * translatable JSON column does not LIKE-match Arabic reliably (it may be
     * stored \u-escaped).
     *
     * @return list<int>
     */
    private function matchingSkillIds(JobTitle $jobTitle, string $search): array
    {
        $needle = mb_strtolower($search);

        return $jobTitle->qualificationSkills
            ->filter(function ($skill) use ($needle) {
                foreach (['en', 'ar'] as $locale) {
                    $name = mb_strtolower((string) $skill->getTranslation('name', $locale, false));
                    if ($name !== '' && str_contains($name, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Per-qualification progress for each learner on the page, down to the
     * courses (Figma 2459:137558, third level).
     *
     * Level 2 reads "N of M Courses"; level 3 lists those M courses with the
     * learner's status on each:
     *   - completed:   a passing exam (App\Support\CourseCompletion, B-104), 100 %;
     *   - in_progress: enrolled, not passed - lecture completion % (the rule
     *                  LectureProgressService uses everywhere else);
     *   - unenrolled:  no enrolment row, 0 % (FG-41: "Unenrolled / Not Yet").
     *
     * "Held" is App\Support\QualificationHolding (D-056): a direct grant, or
     * every linked course completed. M is every course linked to the
     * qualification, enrolled or not. A direct grant marks the qualification
     * earned and 100 % but does not inflate `courses_completed`: the row can
     * read "0 of 3 Courses" while the qualification is held, which is what an
     * externally obtained certificate means.
     *
     * Six queries for the whole page whatever its size: linked courses,
     * direct grants, passes, enrolments, lecture totals, completed lectures.
     *
     * @param  list<int>  $matchedSkillIds
     */
    private function attachQualificationBreakdown(
        JobTitle $jobTitle,
        Collection $learners,
        ?string $search,
        array $matchedSkillIds,
    ): void {
        if ($learners->isEmpty()) {
            return;
        }

        $skills  = $jobTitle->qualificationSkills;
        $userIds = $learners->pluck('id')->map(fn ($id) => (int) $id)->all();
        $granted = $this->directGrants($learners, $skills);
        $locale  = app()->getLocale();

        $links = $skills->isEmpty() ? collect() : DB::table('course_qualification_skills as cqs')
            ->join('courses', 'courses.id', '=', 'cqs.course_id')
            ->whereIn('cqs.qualification_skill_id', $skills->pluck('id'))
            ->orderBy('cqs.course_id')
            ->get(['cqs.qualification_skill_id as skill_id', 'courses.id as course_id', 'courses.title']);

        $courseIds = $links->pluck('course_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $titles    = $links->mapWithKeys(fn ($l) => [(int) $l->course_id => (string) LocalizedJson::pick($l->title, $locale)]);
        $bySkill   = $links->groupBy('skill_id');

        $passed = $enrolled = $progress = [];
        if ($courseIds !== []) {
            $passed = $this->pairs(DB::table('user_exams')
                ->whereIn('user_id', $userIds)
                ->whereIn('course_id', $courseIds)
                ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), CourseCompletion::PASSING_STATUSES));
            $enrolled = $this->pairs(DB::table('users_courses')
                ->whereIn('user_id', $userIds)
                ->whereIn('course_id', $courseIds));
            $progress = $this->lectureProgress($userIds, $courseIds);
        }

        $learners->each(function ($user) use ($skills, $bySkill, $titles, $passed, $enrolled, $progress, $granted, $locale, $search, $matchedSkillIds) {
            $uid  = (int) $user->id;
            $mine = $granted[$uid] ?? [];

            $breakdown = $skills->map(function ($skill) use ($uid, $bySkill, $titles, $passed, $enrolled, $progress, $mine, $locale) {
                $courses = ($bySkill->get($skill->id) ?? collect())->map(function ($link) use ($uid, $titles, $passed, $enrolled, $progress) {
                    $cid = (int) $link->course_id;
                    $key = $uid.':'.$cid;

                    [$status, $percent] = match (true) {
                        isset($passed[$key])   => ['completed', 100],
                        isset($enrolled[$key]) => ['in_progress', $progress[$key] ?? 0],
                        default                => ['unenrolled', 0],
                    };

                    return ['id' => $cid, 'title' => $titles[$cid] ?? '', 'status' => $status, 'percent' => $percent];
                })->values();

                $total     = $courses->count();
                $completed = $courses->where('status', 'completed')->count();
                $direct    = in_array((int) $skill->id, $mine, true);

                return [
                    'id'                => (int) $skill->id,
                    'name'              => $skill->getTranslation('name', $locale),
                    'courses_total'     => $total,
                    'courses_completed' => $completed,
                    'percent'           => QualificationHolding::percent($direct, $total, $completed),
                    'granted_directly'  => $direct,
                    'earned'            => QualificationHolding::holds($direct, $total, $completed),
                    'courses'           => $courses->all(),
                ];
            })->values();

            // Totals always cover every required qualification: compliance
            // does not change because the admin searched.
            $user->qualifications_total     = $breakdown->count();
            // A qualification with no courses attached and no direct grant is
            // not counted - nothing has happened to earn it.
            $user->qualifications_completed = $breakdown->where('earned', true)->count();

            $narrow = $matchedSkillIds !== [] && ! $this->learnerMatches($user, (string) $search);
            $user->qualification_match     = $narrow;
            $user->qualification_breakdown = ($narrow
                ? $breakdown->filter(fn ($q) => in_array($q['id'], $matchedSkillIds, true))->values()
                : $breakdown)->all();
        });
    }

    /** Whether the learner's own name (any language) or employee ID contains the term. */
    private function learnerMatches(object $user, string $search): bool
    {
        $needle = mb_strtolower($search);
        foreach ([$user->name, $user->name_en, $user->name_ar, $user->machine_code] as $value) {
            if ($value !== null && $value !== '' && str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinct (user, course) pairs of a query, as a "user:course" => true set.
     *
     * @return array<string, true>
     */
    private function pairs(\Illuminate\Database\Query\Builder $query): array
    {
        return $query->distinct()->get(['user_id', 'course_id'])
            ->mapWithKeys(fn ($r) => [((int) $r->user_id).':'.((int) $r->course_id) => true])
            ->all();
    }

    /**
     * Lecture completion % per (user, course): completed lectures / lectures,
     * as LectureProgressService::getCourseProgressBatch, for many users at once.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $courseIds
     * @return array<string, int>
     */
    private function lectureProgress(array $userIds, array $courseIds): array
    {
        $totals = DB::table('course_lectures')
            ->whereIn('course_id', $courseIds)
            ->groupBy('course_id')
            ->selectRaw('course_id, COUNT(*) AS n')
            ->pluck('n', 'course_id');

        $out = [];
        DB::table('user_lecture_progress as ulp')
            ->join('course_lectures as cl', 'cl.id', '=', 'ulp.lecture_id')
            ->whereIn('ulp.user_id', $userIds)
            ->whereIn('cl.course_id', $courseIds)
            ->where('ulp.completed', true)
            ->groupBy('ulp.user_id', 'cl.course_id')
            ->get(['ulp.user_id', 'cl.course_id', DB::raw('COUNT(*) AS done')])
            ->each(function ($r) use ($totals, &$out) {
                $total = (int) ($totals[$r->course_id] ?? 0);
                if ($total > 0) {
                    $out[((int) $r->user_id).':'.((int) $r->course_id)] = (int) min(100, round($r->done * 100 / $total));
                }
            });

        return $out;
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
