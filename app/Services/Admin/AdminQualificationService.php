<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Support\QualificationHolding;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard Qualifications page (Figma 2066:100159) and its New / Edit
 * modal (2066:100876): the list with its figures, one qualification with its
 * assignments, and the writes that set name + job titles + learners together.
 *
 * ── The list's figures (human, 2026-09-26; D-056) ───────────────────────────
 * Everything is measured against the people the qualification APPLIES TO:
 * employees whose job title requires it, plus learners granted it directly.
 *
 *   linked courses  courses that grant it
 *   job titles      job titles that require it
 *   learners        size of that group
 *   certified       how many of them hold it (App\Support\QualificationHolding)
 *   completion      their average progress through its linked courses
 *                   (100% for a direct grant)
 *   enrolled        learners enrolled in any linked course, in the group or not
 */
class AdminQualificationService
{
    /** The modal's learner list is a checkbox list, not a bulk tool. */
    public const MAX_LEARNERS = 1000;

    /** Rows per section in the modal's search results. */
    public const ASSIGNEE_LIMIT = 50;

    public function __construct(private readonly LearnerQualificationService $grants) {}

    public function list(?string $search, int $perPage): LengthAwarePaginator
    {
        $page = $this->filtered($search)
            ->withCount(['courses', 'jobTitles'])
            ->orderByDesc('qualification_skills.created_at')
            ->orderByDesc('qualification_skills.id')
            ->paginate($perPage);

        $this->attachFigures($page->getCollection());

        return $page;
    }

    /**
     * Every qualification matching the list's search, with its figures and
     * assignments - the Export. Bounded by the caller.
     */
    public function forExport(?string $search, int $limit): Collection
    {
        $rows = $this->filtered($search)
            ->withCount(['courses', 'jobTitles'])
            ->with(['jobTitles:id,name,name_en,name_ar'])
            ->orderBy('qualification_skills.id')
            ->limit($limit)
            ->get();

        $this->attachFigures($rows);

        $employeeIds = DB::table('user_qualification_skill as g')
            ->join('users', 'users.id', '=', 'g.user_id')
            ->whereIn('g.qualification_skill_id', $rows->pluck('id'))
            ->orderBy('users.machine_code')
            ->get(['g.qualification_skill_id', 'users.machine_code'])
            ->groupBy('qualification_skill_id');

        $rows->each(function (QualificationSkill $q) use ($employeeIds) {
            $q->setAttribute('granted_employee_ids', ($employeeIds->get($q->id) ?? collect())
                ->pluck('machine_code')->filter()->values()->all());
        });

        return $rows;
    }

    /** One qualification with its assignments, for the Edit modal. */
    public function show(QualificationSkill $skill): array
    {
        $jobTitles = $skill->jobTitles()
            ->withCount('users as employees_count')
            ->orderBy('job_titles.name')
            ->get(['job_titles.id', 'job_titles.name', 'job_titles.name_en', 'job_titles.name_ar']);

        $learners = $this->grantedLearners($skill);
        $total    = DB::table('user_qualification_skill')->where('qualification_skill_id', $skill->id)->count();

        return [
            'id'         => $skill->id,
            'name'       => ['en' => $skill->getTranslation('name', 'en', false), 'ar' => $skill->getTranslation('name', 'ar', false)],
            'job_titles' => $jobTitles->map(fn (JobTitle $jt) => $this->jobTitleOption($jt))->values()->all(),
            'learners'   => $learners,
            'learners_total' => $total,
            // More direct grants than the modal can list: it then leaves them
            // alone rather than send back a truncated list that would revoke
            // everyone it did not show.
            'learners_editable' => $total <= self::MAX_LEARNERS,
        ];
    }

    /** @param array{name:array{en:string,ar:string}, job_title_ids?:list<int>, learner_ids?:list<int>} $data */
    public function create(array $data, ?Admin $by): QualificationSkill
    {
        return DB::transaction(function () use ($data, $by) {
            $skill = new QualificationSkill();
            $skill->setTranslations('name', $data['name']);
            $skill->save();

            $this->assign($skill, $data, $by);

            return $skill;
        });
    }

    /** @param array{name:array{en:string,ar:string}, job_title_ids?:list<int>, learner_ids?:list<int>} $data */
    public function update(QualificationSkill $skill, array $data, ?Admin $by): QualificationSkill
    {
        return DB::transaction(function () use ($skill, $data, $by) {
            $skill->setTranslations('name', $data['name']);
            $skill->save();

            $this->assign($skill, $data, $by);

            return $skill;
        });
    }

    /**
     * Search results for the modal's unified list: job titles (with their
     * employee count, as Figma shows) and learners (with their employee ID).
     *
     * @return array{job_titles:list<array>, learners:list<array>}
     */
    public function assignees(?string $search, string $type): array
    {
        $term = $search !== null && trim($search) !== '' ? '%'.$this->escapeLike(mb_strtolower(trim($search))).'%' : null;

        $jobTitles = [];
        if ($type !== 'learners') {
            $jobTitles = JobTitle::query()
                ->withCount('users as employees_count')
                ->when($term, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name_ar) LIKE ?', [$term])))
                ->orderBy('name')
                ->limit(self::ASSIGNEE_LIMIT)
                ->get(['id', 'name', 'name_en', 'name_ar'])
                ->map(fn (JobTitle $jt) => $this->jobTitleOption($jt))
                ->all();
        }

        $learners = [];
        if ($type !== 'job_titles') {
            $learners = User::query()
                ->when($term, fn ($q) => $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(machine_code) LIKE ?', [$term])))
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::ASSIGNEE_LIMIT)
                ->get()
                ->map(fn (User $u) => $this->learnerOption($u))
                ->all();
        }

        return ['job_titles' => $jobTitles, 'learners' => $learners];
    }

    /** Set job titles and direct grants to exactly what the modal sent. */
    private function assign(QualificationSkill $skill, array $data, ?Admin $by): void
    {
        if (array_key_exists('job_title_ids', $data)) {
            $skill->jobTitles()->sync($data['job_title_ids']);
        }

        if (! array_key_exists('learner_ids', $data)) {
            return;
        }

        $wanted  = array_values(array_unique(array_map('intval', $data['learner_ids'])));
        $current = DB::table('user_qualification_skill')
            ->where('qualification_skill_id', $skill->id)
            ->lockForUpdate()
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $removed = array_values(array_diff($current, $wanted));
        if ($removed !== []) {
            // Only the direct grant goes; a learner who also completed the
            // courses still holds it (LearnerQualificationService::revoke).
            DB::table('user_qualification_skill')
                ->where('qualification_skill_id', $skill->id)
                ->whereIn('user_id', $removed)
                ->delete();
        }

        $added = array_values(array_diff($wanted, $current));
        if ($added !== []) {
            $this->grants->grantToMany($skill, $added, $by);
        }
    }

    /**
     * Figures for a page of qualifications, in two grouped queries whatever
     * the page size: enrolled learners, and the target group's size,
     * holders and average progress.
     */
    private function attachFigures(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $enrolled = DB::table('course_qualification_skills as cq')
            ->join('users_courses as uc', 'uc.course_id', '=', 'cq.course_id')
            ->whereIn('cq.qualification_skill_id', $ids)
            ->groupBy('cq.qualification_skill_id')
            ->selectRaw('cq.qualification_skill_id AS q, COUNT(DISTINCT uc.user_id) AS n')
            ->pluck('n', 'q');

        $byJobTitle = DB::table('users as tu')
            ->join('job_title_qualification_skill as tq', 'tq.job_title_id', '=', 'tu.job_title_id')
            ->whereIn('tq.qualification_skill_id', $ids)
            ->select(['tq.qualification_skill_id as q', 'tu.id as u']);

        $granted = DB::table('user_qualification_skill as tg')
            ->whereIn('tg.qualification_skill_id', $ids)
            ->select(['tg.qualification_skill_id as q', 'tg.user_id as u']);

        // UNION (not UNION ALL): someone both required and granted is one
        // person. Each person's values are worked out first, then aggregated:
        // MySQL refuses correlated subqueries inside SUM() / AVG() (1140).
        $people = DB::query()
            ->fromSub($byJobTitle->union($granted), 't')
            ->select(['t.q'])
            ->selectRaw('CASE WHEN '.QualificationHolding::holdsSql('t.u', 't.q').' THEN 1 ELSE 0 END AS holds')
            ->selectRaw(QualificationHolding::progressSql('t.u', 't.q').' AS progress');

        $group = DB::query()
            ->fromSub($people, 'p')
            ->groupBy('p.q')
            ->selectRaw('p.q, COUNT(*) AS learners, SUM(p.holds) AS certified, ROUND(AVG(p.progress) * 100) AS completion')
            ->get()
            ->keyBy('q');

        $rows->each(function (QualificationSkill $q) use ($enrolled, $group) {
            $g = $group->get($q->id);
            $q->setAttribute('enrolled_count', (int) ($enrolled[$q->id] ?? 0));
            $q->setAttribute('learners_count', (int) ($g->learners ?? 0));
            $q->setAttribute('certified_count', (int) ($g->certified ?? 0));
            // No group, no figure: a dash rather than a 0% that reads as failure.
            $q->setAttribute('completion_percent', $g === null ? null : (int) $g->completion);
        });
    }

    private function filtered(?string $search)
    {
        $query = QualificationSkill::query()->select('qualification_skills.*');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.$this->escapeLike(mb_strtolower(trim($search))).'%';
            // JSON_UNQUOTE returns a binary-collated string, so LIKE would be
            // case-sensitive without LOWER().
            $query->where(fn ($w) => $w
                ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, '$.en'))) LIKE ?", [$term])
                ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar'))) LIKE ?", [$term]));
        }

        return $query;
    }

    private function grantedLearners(QualificationSkill $skill): array
    {
        return User::query()
            ->join('user_qualification_skill as g', 'g.user_id', '=', 'users.id')
            ->where('g.qualification_skill_id', $skill->id)
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit(self::MAX_LEARNERS)
            ->get(['users.*'])
            ->map(fn (User $u) => $this->learnerOption($u))
            ->all();
    }

    private function jobTitleOption(JobTitle $jt): array
    {
        return ['id' => $jt->id, 'name' => $jt->getLocalizedName(), 'employees' => (int) ($jt->employees_count ?? 0)];
    }

    private function learnerOption(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->getLocalizedName(), 'employee_id' => $u->machine_code];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
