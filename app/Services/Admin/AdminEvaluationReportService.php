<?php

namespace App\Services\Admin;

use App\Models\Course;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Support\LocalizedJson;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the admin evaluation screens (Figma 2009:88432, 2266:128869,
 * 2169:108198, 2017:52260, 2169:108801, 2169:109264).
 *
 * Data model, as it actually is:
 *   evaluation_categories  = the "Evaluation Template"
 *   evaluations            = its questions; `type` is five | ten | text
 *   user_course_evaluations = one denormalised row per (learner, course, question),
 *                             carrying `answer` and `evaluation_type` (5 | 10 | 0)
 *
 * There is no submission table and no submission id - a "submission" is the set
 * of rows sharing (user_id, course_id, evaluation_category_id). The endpoints
 * therefore address one by that natural key rather than by inventing a
 * surrogate.
 *
 * In the legacy flow every template is shown on every course flagged
 * `is_evaluate` (UserCourseEvaluationService::getForm() returns them all), so a
 * template's audience is "learners enrolled in an evaluable course".
 *
 * ── Score (D-054; Q-033 and Q-034, approved under D-048) ────────────────────
 * `score` is on a /5 scale: the mean of the answered scaled questions, each
 * first normalised to its own scale (answer / scale_max). It is computed and
 * rounded in SQL, and `passed` compares that same rounded value with the pass
 * threshold, so a list row, a filter and a detail page can never disagree about
 * a 2.96 that displays as "3.0". `total`, `max_total` and `ratio` stay in the
 * payloads (B3 contract). Free text is counted but never scored.
 *
 * Names are read from the live course / template / question / user rows in the
 * request locale; the snapshot columns on user_course_evaluations (written in
 * whatever locale the learner used) are only a fallback for deleted sources.
 */
class AdminEvaluationReportService
{
    /** Scale maximum per question type, keyed by the stored `evaluations.type`. */
    private const SCALE_MAX = Evaluation::SCALE_MAX;

    public const RESULTS = ['passed', 'failed', 'unscored'];

    /** Bound on each filter-option list; the lists are distinct values in evaluation data. */
    private const OPTIONS_LIMIT = 500;

    public function scoreMax(): int
    {
        return (int) config('evaluations.score_max', 5);
    }

    public function passThreshold(): float
    {
        return (float) config('evaluations.pass_threshold', 3.0);
    }

    /** True / false against the pass threshold; null when there is no score. */
    public function passed(?float $score): ?bool
    {
        return $score === null ? null : $score >= $this->passThreshold();
    }

    /**
     * Course overview histogram (Figma 2266:128869 / 2266:130142).
     *
     * "58 reviews · 3 with comments", average score, learners scored, question
     * count, and the star histogram.
     */
    public function courseSummary(Course $course): array
    {
        $ratings = DB::table('course_ratings')->where('course_id', $course->id);

        $histogram = (clone $ratings)
            ->select('rating', DB::raw('COUNT(*) as c'))
            ->groupBy('rating')
            ->pluck('c', 'rating');

        // 5 down to 1, so the UI never has to fill gaps itself.
        $stars = [];
        for ($star = 5; $star >= 1; $star--) {
            $stars[] = ['value' => $star, 'count' => (int) ($histogram[$star] ?? 0)];
        }

        $reviewCount = (clone $ratings)->count();

        return [
            'course_id'     => $course->id,
            'reviews'       => $reviewCount,
            'with_comments' => (clone $ratings)->whereNotNull('comment')->where('comment', '!=', '')->count(),
            'average'       => $reviewCount > 0
                ? round((float) (clone $ratings)->avg('rating'), 1)
                : null,
            'scale_max'     => 5,
            'distribution'  => $stars,
            'learners_scored' => DB::table('user_course_evaluations')
                ->where('course_id', $course->id)
                ->distinct()
                ->count('user_id'),
            'learners_enrolled' => DB::table('users_courses')
                ->where('course_id', $course->id)
                ->distinct()
                ->count('user_id'),
            'questions_answered' => DB::table('user_course_evaluations')
                ->where('course_id', $course->id)
                ->distinct()
                ->count('evaluation_id'),
        ];
    }

    /**
     * The /5 score and submission count of each course, in one grouped query,
     * for the course list's Evaluation column and the course header card
     * (Figma 2430:135164, 2266:128869). A course nobody evaluated is absent,
     * which callers render as unscored - never as 0.
     *
     * @param  list<int>  $courseIds
     * @return array<int, array{score: float|null, submissions: int}>
     */
    public function courseScores(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('user_course_evaluations as uce')
            ->whereIn('uce.course_id', $courseIds)
            ->groupBy('uce.course_id')
            ->get([
                'uce.course_id',
                DB::raw("COUNT(DISTINCT CONCAT(uce.user_id, '-', COALESCE(uce.evaluation_category_id, 0))) as submissions"),
                DB::raw($this->scoreSql('uce').' as score'),
            ])
            ->mapWithKeys(fn ($r) => [(int) $r->course_id => [
                'score'       => $r->score !== null ? (float) $r->score : null,
                'submissions' => (int) $r->submissions,
            ]])
            ->all();
    }

    /**
     * Evaluation Templates list (Figma 2009:88432): one row per template with
     * its question count and the aggregate of its responses.
     *
     * Course and instructor filters narrow the RESPONSES a row aggregates, and
     * then list only templates that have a matching response - "how did the
     * templates do on this course". The result filter compares the template
     * score with the pass threshold. The date range is on the last response.
     *
     * @param  array{search?:string, course_ids?:list<int>, instructor_ids?:list<int>, results?:list<string>, scored_from?:string, scored_to?:string, sort?:string, dir?:string}  $f
     */
    public function templates(array $f, int $perPage): LengthAwarePaginator
    {
        $responses = DB::table('user_course_evaluations as uce')
            ->whereNotNull('uce.evaluation_category_id')
            ->tap(fn (Builder $q) => $this->applyResponseFilters($q, $f))
            ->groupBy('uce.evaluation_category_id')
            ->select([
                'uce.evaluation_category_id',
                DB::raw('COUNT(DISTINCT uce.user_id) as learners_scored'),
                DB::raw("COUNT(DISTINCT CONCAT(uce.user_id, '-', uce.course_id)) as submissions"),
                DB::raw('MAX(uce.created_at) as last_scored_at'),
                DB::raw($this->scoreSql('uce').' as score'),
            ]);

        $questions = DB::table('evaluations')
            ->groupBy('evaluation_category_id')
            ->select('evaluation_category_id', DB::raw('COUNT(*) as questions'));

        $narrowed = ! empty($f['course_ids']) || ! empty($f['instructor_ids']);

        $query = DB::table('evaluation_categories as t')
            ->leftJoinSub($responses, 'r', 'r.evaluation_category_id', '=', 't.id')
            ->leftJoinSub($questions, 'q', 'q.evaluation_category_id', '=', 't.id')
            ->leftJoin('courses as tc', 'tc.id', '=', 't.course_id')
            ->leftJoin('course_sections as ts', 'ts.id', '=', 't.section_id')
            ->when($narrowed, fn ($q) => $q->whereNotNull('r.evaluation_category_id'))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('t.name', 'like', $this->like($s)))
            ->when($f['results'] ?? null, fn ($q, $results) => $this->whereResult($q, 'r.score', $results))
            ->when($f['scored_from'] ?? null, fn ($q, $d) => $q->where('r.last_scored_at', '>=', $d.' 00:00:00'))
            ->when($f['scored_to'] ?? null, fn ($q, $d) => $q->where('r.last_scored_at', '<', $this->dayAfter($d)))
            ->select([
                't.id', 't.name', 't.created_at', 't.updated_at',
                DB::raw('COALESCE(q.questions, 0) as questions'),
                DB::raw('COALESCE(r.learners_scored, 0) as learners_scored'),
                DB::raw('COALESCE(r.submissions, 0) as submissions'),
                'r.last_scored_at',
                'r.score',
                't.course_id',
                't.section_id',
                'tc.title as course_title',
                'ts.name as section_name',
            ])
            ->tap(fn (Builder $q) => $this->selectEligible($q, $f))
            // Across ALL responses, not the filtered ones: a template answered on
            // another course is read-only too.
            ->selectSub(fn ($x) => $x->from('user_course_evaluations as ul')
                ->whereColumn('ul.evaluation_category_id', 't.id')->selectRaw('1')->limit(1), 'has_responses');

        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ($f['sort'] ?? 'created_at') {
            'name'        => $query->orderByRaw($this->localizedSortSql('t.name').' '.$dir),
            'last_scored' => $query->orderBy('r.last_scored_at', $dir),
            default       => $query->orderBy('t.created_at', $dir),
        };
        $query->orderBy('t.id', $dir);

        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function ($row) {
            $row->name              = LocalizedJson::pick($row->name);
            $row->score             = $row->score !== null ? (float) $row->score : null;
            $row->learners_eligible = (int) $row->eligible_enrolled + (int) $row->eligible_answered_only;
            $row->course_name       = LocalizedJson::pick($row->course_title);
            $row->section_name      = LocalizedJson::pick($row->section_name);

            return $row;
        });

        return $page;
    }

    /**
     * Per-question distribution for one template (Figma 2169:108198), plus the
     * header tiles: template score, learners scored of eligible, questions.
     */
    public function templateResults(EvaluationCategory $template): array
    {
        $questions = DB::table('evaluations')
            ->where('evaluation_category_id', $template->id)
            ->orderBy('id')
            ->get(['id', 'title', 'type', 'is_required', 'scale_label_min', 'scale_label_max']);

        // One grouped query for every question in the template, rather than a
        // query per question.
        $answers = DB::table('user_course_evaluations')
            ->whereIn('evaluation_id', $questions->pluck('id'))
            ->select('evaluation_id', 'answer', DB::raw('COUNT(*) as c'))
            ->groupBy('evaluation_id', 'answer')
            ->get()
            ->groupBy('evaluation_id');

        $rendered = $questions->map(function ($question) use ($answers) {
            $rows      = $answers->get($question->id, collect());
            $scaleMax  = self::SCALE_MAX[$question->type] ?? null;
            $responses = (int) $rows->sum('c');

            return [
                'id'        => (int) $question->id,
                // evaluations.title is Spatie-translatable; the query builder
                // returns the stored JSON as is.
                'title'     => LocalizedJson::pick($question->title),
                'type'      => $question->type,
                'required'  => (bool) $question->is_required,
                'scale_max' => $scaleMax,
                // The worded ends of a scale question ("1 - Poor ... 5 - Excellent").
                'scale_label_min' => LocalizedJson::pick($question->scale_label_min),
                'scale_label_max' => LocalizedJson::pick($question->scale_label_max),
                'responses' => $responses,
                'average'   => $this->averageOf($rows, $scaleMax),
                // Null for free-text questions: a distribution over prose is
                // meaningless, and inventing buckets would be fabrication.
                'distribution' => $scaleMax === null
                    ? null
                    : $this->distributionOf($rows, $scaleMax),
            ];
        });

        $summary = DB::table('user_course_evaluations as uce')
            ->where('uce.evaluation_category_id', $template->id)
            ->first([
                DB::raw('COUNT(DISTINCT uce.user_id) as learners_scored'),
                DB::raw("COUNT(DISTINCT CONCAT(uce.user_id, '-', uce.course_id)) as submissions"),
                DB::raw('MAX(uce.created_at) as last_scored_at'),
                DB::raw($this->scoreSql('uce').' as score'),
            ]);

        $score = $summary->score !== null ? (float) $summary->score : null;

        $eligible = DB::table('evaluation_categories as t')->where('t.id', $template->id)
            ->select('t.id')
            ->tap(fn (Builder $q) => $this->selectEligible($q, []))
            ->first();

        $template->loadMissing(['course:id,title', 'section:id,name']);

        return [
            'template' => [
                'id'         => $template->id,
                'name'       => $template->name,
                'questions'  => $questions->count(),
                'created_at' => $template->created_at?->toIso8601String(),
                // null = all evaluable courses / all cohorts.
                'course'     => $template->course ? ['id' => $template->course->id, 'name' => $template->course->title] : null,
                'cohort'     => $template->section ? ['id' => $template->section->id, 'name' => $template->section->name] : null,
                // Read-only once answered (2026-09-26).
                'locked'     => (int) $summary->submissions > 0,
            ],
            'summary' => [
                'score'             => $score,
                'score_max'         => $this->scoreMax(),
                'pass_threshold'    => $this->passThreshold(),
                'passed'            => $this->passed($score),
                'learners_scored'   => (int) $summary->learners_scored,
                'learners_eligible' => (int) $eligible->eligible_enrolled + (int) $eligible->eligible_answered_only,
                'submissions'       => (int) $summary->submissions,
                'last_scored_at'    => $summary->last_scored_at,
            ],
            'questions' => $rendered->all(),
        ];
    }

    /**
     * "View Learners scores" list (Figma 2017:52260).
     *
     * One row per submission - (learner, course, template) - with the number of
     * questions answered and the score. Built as a grouped query so paging
     * happens in the database.
     *
     * @param  array{search?:string, template_id?:int, course_ids?:list<int>, instructor_ids?:list<int>, learner_ids?:list<int>, results?:list<string>, dir?:string}  $f
     */
    public function scores(array $f, int $perPage): LengthAwarePaginator
    {
        $page = DB::table('user_course_evaluations as uce')
            ->leftJoin('users as u', 'u.id', '=', 'uce.user_id')
            ->leftJoin('courses as c', 'c.id', '=', 'uce.course_id')
            ->leftJoin('evaluation_categories as t', 't.id', '=', 'uce.evaluation_category_id')
            ->tap(fn (Builder $q) => $this->applyResponseFilters($q, $f))
            ->when($f['template_id'] ?? null, fn ($q, $id) => $q->where('uce.evaluation_category_id', $id))
            ->when($f['learner_ids'] ?? null, fn ($q, $ids) => $q->whereIn('uce.user_id', $ids))
            ->when($f['search'] ?? null, function ($q, $search) {
                $like = $this->like($search);
                $q->where(fn ($w) => $w->where('uce.user_machine_code', 'like', $like)
                    ->orWhere('u.name', 'like', $like)
                    ->orWhere('c.title', 'like', $like)
                    ->orWhere('t.name', 'like', $like));
            })
            ->groupBy('uce.user_id', 'uce.course_id', 'uce.evaluation_category_id')
            ->select([
                'uce.user_id',
                'uce.course_id',
                'uce.evaluation_category_id',
                DB::raw('MAX(u.name) as learner_name'),
                DB::raw('MAX(uce.user_machine_code) as user_machine_code'),
                DB::raw('MAX(uce.user_department) as user_department'),
                DB::raw('MAX(c.title) as course_title'),
                DB::raw('MAX(uce.course_name) as course_name'),
                DB::raw('MAX(t.name) as template_name'),
                DB::raw('MAX(uce.evaluation_category_name) as evaluation_category_name'),
                DB::raw('MAX(t.created_at) as template_created_at'),
                DB::raw('COUNT(*) as answers_count'),
                // Only scale answers contribute; free text (evaluation_type 0)
                // is excluded from both the total and the maximum.
                DB::raw('SUM(CASE WHEN uce.evaluation_type > 0 THEN CAST(uce.answer AS DECIMAL(10,2)) ELSE 0 END) as total'),
                DB::raw('SUM(CASE WHEN uce.evaluation_type > 0 THEN uce.evaluation_type ELSE 0 END) as max_total'),
                DB::raw($this->scoreSql('uce').' as score'),
                DB::raw('MAX(uce.created_at) as submitted_at'),
            ])
            ->when($f['results'] ?? null, fn ($q, $results) => $this->havingResult($q, $results))
            ->orderBy('submitted_at', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderBy('uce.user_id')
            ->orderBy('uce.course_id')
            ->paginate($perPage);

        $page->getCollection()->transform(function ($row) {
            $row->course_name   = LocalizedJson::pick($row->course_title) ?? $row->course_name;
            $row->template_name = LocalizedJson::pick($row->template_name) ?? $row->evaluation_category_name;
            $row->score         = $row->score !== null ? (float) $row->score : null;

            return $row;
        });

        return $page;
    }

    /**
     * One learner's submission (Figma 2169:108801 passing, 2169:109264 failing).
     *
     * Addressed by the natural key because no submission id exists. Returns
     * every answer plus the same total / max / score as the list, so the two
     * screens cannot disagree.
     */
    public function submission(int $userId, int $courseId, ?int $templateId = null): ?array
    {
        $scope = fn (Builder $q) => $q->where('uce.user_id', $userId)
            ->where('uce.course_id', $courseId)
            ->when($templateId, fn ($q) => $q->where('uce.evaluation_category_id', $templateId));

        $rows = DB::table('user_course_evaluations as uce')
            ->leftJoin('evaluations as e', 'e.id', '=', 'uce.evaluation_id')
            ->tap($scope)
            ->orderBy('uce.evaluation_id')
            ->get(['uce.*', 'e.title as live_title', 'e.type as live_type']);

        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();

        $scaled   = $rows->where('evaluation_type', '>', 0);
        $total    = (float) $scaled->sum(fn ($r) => (float) $r->answer);
        $maxTotal = (float) $scaled->sum(fn ($r) => (float) $r->evaluation_type);

        // The list's own expression, so the detail cannot round differently.
        $score = DB::table('user_course_evaluations as uce')->tap($scope)
            ->value(DB::raw($this->scoreSql('uce')));
        $score = $score !== null ? (float) $score : null;

        $learner  = DB::table('users')->where('id', $userId)->value('name');
        $course   = DB::table('courses')->where('id', $courseId)->value('title');
        $template = $first->evaluation_category_id !== null
            ? DB::table('evaluation_categories')->where('id', $first->evaluation_category_id)->first(['name', 'created_at'])
            : null;

        return [
            'learner' => [
                'id'          => (int) $first->user_id,
                'name'        => $learner,
                'employee_id' => $first->user_machine_code,
                'department'  => $first->user_department,
            ],
            'course' => [
                'id'   => (int) $first->course_id,
                'name' => LocalizedJson::pick($course) ?? $first->course_name,
            ],
            'template' => [
                'id'         => $first->evaluation_category_id !== null ? (int) $first->evaluation_category_id : null,
                'name'       => LocalizedJson::pick($template?->name) ?? $first->evaluation_category_name,
                'created_at' => $template?->created_at,
            ],
            'instructor' => [
                'id'   => $first->instructor_id !== null ? (int) $first->instructor_id : null,
                'name' => $first->instructor_name,
            ],
            'submitted_at'   => $rows->max('created_at'),
            'total'          => $total,
            'max_total'      => $maxTotal,
            // Normalised 0-1, kept from the B3 contract.
            'ratio'          => $maxTotal > 0 ? round($total / $maxTotal, 4) : null,
            'score'          => $score,
            'score_max'      => $this->scoreMax(),
            'pass_threshold' => $this->passThreshold(),
            'passed'         => $this->passed($score),
            'answers'        => $rows->map(fn ($r) => [
                'evaluation_id' => (int) $r->evaluation_id,
                'title'         => LocalizedJson::pick($r->live_title) ?? $r->evaluation_title,
                'type'          => $r->live_type ?? match ((int) $r->evaluation_type) {
                    5       => 'five',
                    10      => 'ten',
                    default => 'text',
                },
                'scale_max'     => (int) $r->evaluation_type ?: null,
                'answer'        => $r->answer,
                'is_text'       => (int) $r->evaluation_type === 0,
            ])->values()->all(),
        ];
    }

    /**
     * Choices for the Instructors and Courses chips: only values that occur in
     * evaluation responses, so the picker never offers a filter that must
     * return nothing, and an admin holding only view-evaluations does not need
     * view-users to fill it.
     *
     * @return array{instructors: list<array{id:int,name:?string}>, courses: list<array{id:int,name:?string}>}
     */
    public function filterOptions(): array
    {
        $instructors = DB::table('user_course_evaluations as uce')
            ->leftJoin('instructors as i', 'i.id', '=', 'uce.instructor_id')
            ->groupBy('uce.instructor_id')
            ->orderBy('uce.instructor_id')
            ->limit(self::OPTIONS_LIMIT)
            ->get(['uce.instructor_id as id', DB::raw('MAX(i.name) as live'), DB::raw('MAX(uce.instructor_name) as snapshot')]);

        $courses = DB::table('user_course_evaluations as uce')
            ->leftJoin('courses as c', 'c.id', '=', 'uce.course_id')
            ->groupBy('uce.course_id')
            ->orderBy('uce.course_id')
            ->limit(self::OPTIONS_LIMIT)
            ->get(['uce.course_id as id', DB::raw('MAX(c.title) as live'), DB::raw('MAX(uce.course_name) as snapshot')]);

        $shape = fn (Collection $rows) => $rows
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => LocalizedJson::pick($r->live) ?? $r->snapshot])
            ->sortBy(fn ($o) => mb_strtolower((string) $o['name']))
            ->values()
            ->all();

        return ['instructors' => $shape($instructors), 'courses' => $shape($courses)];
    }

    /** Learners who have submitted an evaluation, for the Learners chip. */
    public function learnerOptions(?string $search, int $perPage): LengthAwarePaginator
    {
        return DB::table('users as u')
            ->whereIn('u.id', fn ($q) => $q->select('user_id')->from('user_course_evaluations'))
            ->when($search, function ($q, $s) {
                $like = $this->like($s);
                $q->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('u.machine_code', 'like', $like));
            })
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->select(['u.id', 'u.name', 'u.machine_code'])
            ->paginate($perPage);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** scoreSql() for other services that must score exactly as the reports do. */
    public function scoreExpression(string $alias): string
    {
        return $this->scoreSql($alias);
    }

    /**
     * The /5 score of a set of response rows, rounded to one decimal in SQL.
     * DECIMAL throughout, so ROUND is half-away-from-zero like PHP's round().
     */
    private function scoreSql(string $a): string
    {
        $max = $this->scoreMax();

        return "ROUND(AVG(CASE WHEN {$a}.evaluation_type > 0"
            ." THEN CAST({$a}.answer AS DECIMAL(10,4)) / CAST({$a}.evaluation_type AS DECIMAL(10,4)) END) * {$max}, 1)";
    }

    /** Course and instructor filters on user_course_evaluations rows. */
    private function applyResponseFilters(Builder $q, array $f, string $alias = 'uce'): void
    {
        $q->when($f['course_ids'] ?? null, fn ($q, $ids) => $q->whereIn("{$alias}.course_id", $ids))
            ->when($f['instructor_ids'] ?? null, fn ($q, $ids) => $q->whereIn("{$alias}.instructor_id", $ids));
    }

    /** WHERE on an already-aggregated score column (a joined subquery). */
    private function whereResult(Builder $q, string $column, array $results): void
    {
        $threshold = $this->passThreshold();

        $q->where(function ($w) use ($column, $results, $threshold) {
            foreach (array_unique($results) as $result) {
                match ($result) {
                    'passed'   => $w->orWhere($column, '>=', $threshold),
                    'failed'   => $w->orWhere($column, '<', $threshold),
                    'unscored' => $w->orWhereNull($column),
                };
            }
        });
    }

    /** HAVING on the score alias of a grouped query. */
    private function havingResult(Builder $q, array $results): void
    {
        $parts    = [];
        $bindings = [];

        foreach (array_unique($results) as $result) {
            if ($result === 'unscored') {
                $parts[] = 'score IS NULL';
            } else {
                $parts[]    = $result === 'passed' ? 'score >= ?' : 'score < ?';
                $bindings[] = $this->passThreshold();
            }
        }

        $q->havingRaw('('.implode(' OR ', $parts).')', $bindings);
    }

    /**
     * Adds the learners a template (alias `t`) is put to, as two correlated
     * counts summed by the caller: those enrolled in an evaluable course the
     * template covers (its course, or every course; its cohort, or every
     * cohort), plus those who answered it without such an enrolment (a course
     * can stop being evaluable after answers exist). Correlated subqueries, so
     * a page of templates is still one query. The chip filters narrow both.
     */
    private function selectEligible(Builder $q, array $f): void
    {
        $inScope = fn (Builder $w, string $uc) => $w
            ->where(fn ($x) => $x->whereNull('t.course_id')->orWhereColumn("{$uc}.course_id", 't.course_id'))
            ->where(fn ($x) => $x->whereNull('t.section_id')->orWhereColumn("{$uc}.group_id", 't.section_id'))
            ->when($f['course_ids'] ?? null, fn ($x, $ids) => $x->whereIn("{$uc}.course_id", $ids))
            ->when($f['instructor_ids'] ?? null, fn ($x, $ids) => $x->whereIn("{$uc}.course_id",
                fn ($s) => $s->select('course_id')->from('courses_instructors')->whereIn('instructor_id', $ids)));

        $enrolled = DB::table('users_courses as uc')
            ->join('courses as c', 'c.id', '=', 'uc.course_id')
            ->where('c.is_evaluate', 1)
            ->tap(fn (Builder $w) => $inScope($w, 'uc'))
            ->selectRaw('COUNT(DISTINCT uc.user_id)');

        $answeredOnly = DB::table('user_course_evaluations as ua')
            ->whereColumn('ua.evaluation_category_id', 't.id')
            ->tap(fn (Builder $w) => $this->applyResponseFilters($w, $f, 'ua'))
            ->whereNotExists(fn ($x) => $x->from('users_courses as uc2')
                ->join('courses as c2', 'c2.id', '=', 'uc2.course_id')
                ->where('c2.is_evaluate', 1)
                ->whereColumn('uc2.user_id', 'ua.user_id')
                ->tap(fn (Builder $w) => $inScope($w, 'uc2'))
                ->selectRaw('1'))
            ->selectRaw('COUNT(DISTINCT ua.user_id)');

        $q->selectSub($enrolled, 'eligible_enrolled')->selectSub($answeredOnly, 'eligible_answered_only');
    }

    /** ORDER BY a Spatie JSON column in the request locale; plain strings sort as they are. */
    private function localizedSortSql(string $column): string
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return "CASE WHEN JSON_VALID({$column}) THEN JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$locale}')) ELSE {$column} END";
    }

    /** A LIKE pattern with the caller's own wildcards escaped. */
    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    private function dayAfter(string $date): string
    {
        return Carbon::parse($date)->addDay()->toDateString().' 00:00:00';
    }

    /** Weighted average of numeric answers; null when there is nothing numeric. */
    private function averageOf(Collection $rows, ?int $scaleMax): ?float
    {
        if ($scaleMax === null) {
            return null;
        }

        $count = 0;
        $sum   = 0.0;

        foreach ($rows as $row) {
            if (! is_numeric($row->answer)) {
                continue;
            }
            $sum   += (float) $row->answer * (int) $row->c;
            $count += (int) $row->c;
        }

        return $count > 0 ? round($sum / $count, 1) : null;
    }

    /**
     * Value-by-value counts from scaleMax down to 1, with gaps filled as 0 so
     * the UI never has to reconstruct missing buckets.
     *
     * @return list<array{value:int,count:int}>
     */
    private function distributionOf(Collection $rows, int $scaleMax): array
    {
        $byValue = [];
        foreach ($rows as $row) {
            if (is_numeric($row->answer)) {
                $byValue[(int) $row->answer] = ($byValue[(int) $row->answer] ?? 0) + (int) $row->c;
            }
        }

        $out = [];
        for ($value = $scaleMax; $value >= 1; $value--) {
            $out[] = ['value' => $value, 'count' => (int) ($byValue[$value] ?? 0)];
        }

        return $out;
    }
}
