<?php

namespace App\Services\Admin;

use App\Models\Course;
use App\Models\EvaluationCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the admin evaluation screens (Figma 2266:128869, 2169:108198,
 * 2017:52260, 2169:108801, 2169:109264).
 *
 * Data model, as it actually is:
 *   evaluation_categories  = the "Evaluation Template"
 *   evaluations            = its questions; `type` is five | ten | text
 *   user_course_evaluations = one denormalised row per (learner, course, question),
 *                             carrying `answer` and `evaluation_type` (5 | 10 | 0)
 *
 * There is no submission table and no submission id — a "submission" is the set
 * of rows sharing (user_id, course_id, evaluation_category_id). The endpoints
 * therefore address one by that natural key rather than by inventing a
 * surrogate.
 *
 * ── On the score scale (FG-12) ───────────────────────────────────────────────
 * The Figma contradicts itself: 2017:52260 shows scores as "/105" while
 * 2266:130142 shows "4.3/5.0". Nothing here picks a winner. Every payload
 * returns the facts the database can support — per-question `average`,
 * `scale_max`, the full `distribution`, plus a submission's `total`,
 * `max_total` and `average` — and leaves presentation to the UI. FG-12 stays
 * open for the designer.
 *
 * Text answers are counted but never averaged; `type = text` carries
 * `evaluation_type = 0` and its `answer` is free prose.
 */
class AdminEvaluationReportService
{
    /** Scale maximum per question type, keyed by the stored `evaluations.type`. */
    private const SCALE_MAX = ['five' => 5, 'ten' => 10];

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
     * Per-question distribution for one template (Figma 2169:108198).
     *
     * Each question card shows "N total Evaluations", an average with its
     * count, and a value-by-value breakdown.
     */
    public function templateResults(EvaluationCategory $template): array
    {
        $questions = DB::table('evaluations')
            ->where('evaluation_category_id', $template->id)
            ->orderBy('id')
            ->get(['id', 'title', 'type', 'is_required']);

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
                'title'     => $question->title,
                'type'      => $question->type,
                'required'  => (bool) $question->is_required,
                'scale_max' => $scaleMax,
                'responses' => $responses,
                'average'   => $this->averageOf($rows, $scaleMax),
                // Null for free-text questions: a distribution over prose is
                // meaningless, and inventing buckets would be fabrication.
                'distribution' => $scaleMax === null
                    ? null
                    : $this->distributionOf($rows, $scaleMax),
            ];
        });

        return [
            'template' => [
                'id'        => $template->id,
                'name'      => $template->name,
                'questions' => $questions->count(),
            ],
            'questions' => $rendered->all(),
        ];
    }

    /**
     * "View Learners scores" list (Figma 2017:52260).
     *
     * One row per submission — (learner, course, template) — with the number of
     * questions answered and the scored total. Built as a grouped query so
     * paging happens in the database.
     */
    public function scores(int $perPage, ?int $courseId, ?int $templateId, ?string $search): LengthAwarePaginator
    {
        return DB::table('user_course_evaluations as uce')
            ->when($courseId, fn ($q) => $q->where('uce.course_id', $courseId))
            ->when($templateId, fn ($q) => $q->where('uce.evaluation_category_id', $templateId))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('uce.user_machine_code', 'like', "%{$search}%")
                    ->orWhere('uce.course_name', 'like', "%{$search}%");
            }))
            ->groupBy(
                'uce.user_id',
                'uce.course_id',
                'uce.evaluation_category_id',
                'uce.user_machine_code',
                'uce.user_department',
                'uce.course_name',
                'uce.evaluation_category_name',
            )
            ->select([
                'uce.user_id',
                'uce.course_id',
                'uce.evaluation_category_id',
                'uce.user_machine_code',
                'uce.user_department',
                'uce.course_name',
                'uce.evaluation_category_name',
                DB::raw('COUNT(*) as answers_count'),
                // Only scale answers contribute; free text (evaluation_type 0)
                // is excluded from both the total and the maximum.
                DB::raw('SUM(CASE WHEN uce.evaluation_type > 0 THEN CAST(uce.answer AS DECIMAL(10,2)) ELSE 0 END) as total'),
                DB::raw('SUM(CASE WHEN uce.evaluation_type > 0 THEN uce.evaluation_type ELSE 0 END) as max_total'),
                DB::raw('MAX(uce.created_at) as submitted_at'),
            ])
            ->orderByDesc(DB::raw('MAX(uce.created_at)'))
            ->paginate($perPage);
    }

    /**
     * One learner's submission (Figma 2169:108801 passing, 2169:109264 failing).
     *
     * Addressed by the natural key because no submission id exists. Returns
     * every answer plus the same total/max/average trio as the list, so the two
     * screens cannot disagree.
     */
    public function submission(int $userId, int $courseId, ?int $templateId = null): ?array
    {
        $rows = DB::table('user_course_evaluations')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->when($templateId, fn ($q) => $q->where('evaluation_category_id', $templateId))
            ->orderBy('evaluation_id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();

        $scaled   = $rows->where('evaluation_type', '>', 0);
        $total    = (float) $scaled->sum(fn ($r) => (float) $r->answer);
        $maxTotal = (float) $scaled->sum(fn ($r) => (float) $r->evaluation_type);

        return [
            'learner' => [
                'id'           => (int) $first->user_id,
                'employee_id'  => $first->user_machine_code,
                'department'   => $first->user_department,
            ],
            'course' => [
                'id'   => (int) $first->course_id,
                'name' => $first->course_name,
            ],
            'template' => [
                'id'   => $first->evaluation_category_id !== null ? (int) $first->evaluation_category_id : null,
                'name' => $first->evaluation_category_name,
            ],
            'instructor' => [
                'id'   => $first->instructor_id !== null ? (int) $first->instructor_id : null,
                'name' => $first->instructor_name,
            ],
            'submitted_at' => $rows->max('created_at'),
            'total'        => $total,
            'max_total'    => $maxTotal,
            // Normalised 0-1 so a UI can render either "/105" or a 5-point
            // average without this service choosing between them (FG-12).
            'ratio'        => $maxTotal > 0 ? round($total / $maxTotal, 4) : null,
            'answers'      => $rows->map(fn ($r) => [
                'evaluation_id' => (int) $r->evaluation_id,
                'title'         => $r->evaluation_title,
                'scale_max'     => (int) $r->evaluation_type ?: null,
                'answer'        => $r->answer,
                'is_text'       => (int) $r->evaluation_type === 0,
            ])->values()->all(),
        ];
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
