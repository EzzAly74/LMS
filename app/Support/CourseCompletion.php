<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of "this learner completed this course" (B-104,
 * D-082, D-086).
 *
 * Every screen uses this class: the Website (My Learnings, profile counters,
 * qualifications), the mobile API, and the Dashboard (learners list, learner
 * detail, job titles, qualifications, home widgets, reports). Before D-086 the
 * Dashboard matched `user_exams.status IN ('passed', 'completed')`, values the
 * application never writes (it writes 'success' / 'fail'), so every Dashboard
 * completion figure read 0 while the Website showed the real ones.
 *
 * ── The rule (D-082, D-094) ──────────────────────────────────────────────────
 * Every course issues a certificate (human, 2026-09-27; confirmed 2026-10-13),
 * so a course is completed when the learner holds an ACTIVE certificate for
 * it. Issuance enforces the admin-configured certificate rule (attendance,
 * score or both; D-084) on top of the course's completion event (D-091), so
 * nothing completes a course without meeting it; a revoked certificate is
 * not a completion and is never re-issued automatically.
 *
 * The completion moment (`completed_at`) is the certificate's issue date.
 */
final class CourseCompletion
{
    /**
     * A `user_exams` row (alias `$alias`) is a submission, not an opened
     * attempt: a rich quiz (one with `question_en` questions) once
     * `submission_status = 'submitted'`; a legacy exam always, since the legacy
     * flow creates the row only on submit (B-162). `user_degree` cannot tell
     * them apart: it is NOT NULL with a default of 0.
     */
    public static function submittedExamSql(string $alias): string
    {
        return "({$alias}.submission_status = 'submitted' OR NOT EXISTS (
            SELECT 1 FROM course_exam_questions rich_q
            WHERE rich_q.course_exam_id = {$alias}.exam_id AND rich_q.question_en IS NOT NULL
        ))";
    }

    /**
     * A correlated boolean SQL fragment: does `$userColumn` complete
     * `$courseColumn`?
     *
     * Both arguments are SQL column references supplied by calling code, never
     * by user input — they are interpolated, so they must stay that way.
     */
    public static function existsSql(string $userColumn, string $courseColumn): string
    {
        // Every course issues a certificate (human, 2026-09-27 and 2026-10-13):
        // completed = holds an ACTIVE certificate for it (D-094).
        return "EXISTS (
            SELECT 1 FROM user_certificates ccert
            WHERE ccert.user_id = {$userColumn}
              AND ccert.course_id = {$courseColumn}
              AND ccert.status = 'active'
        )";
    }

    /**
     * Completed (user_id, course_id) pairs with the moment completion happened:
     * the learner's active certificate and its issue date (D-094).
     *
     * @param  list<int>|null  $userIds    limit to these learners (null: all)
     * @param  list<int>|null  $courseIds  limit to these courses (null: all)
     */
    public static function query(?array $userIds = null, ?array $courseIds = null): Builder
    {
        return DB::table('user_certificates as ccert')
            ->where('ccert.status', 'active')
            ->when($userIds !== null, fn (Builder $b) => $b->whereIn('ccert.user_id', $userIds ?: [0]))
            ->when($courseIds !== null, fn (Builder $b) => $b->whereIn('ccert.course_id', $courseIds ?: [0]))
            ->selectRaw('ccert.user_id, ccert.course_id, MIN(COALESCE(ccert.issued_at, ccert.created_at)) AS completed_at')
            ->groupBy('ccert.user_id', 'ccert.course_id');
    }

    /**
     * The course ids a single learner has completed.
     *
     * @return Collection<int, int>
     */
    public static function courseIdsFor(int $userId): Collection
    {
        return self::query([$userId])
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * Completed pairs for a batch of learners, keyed "userId:courseId".
     *
     * @param  list<int>       $userIds
     * @param  list<int>|null  $courseIds
     * @return array<string, true>
     */
    public static function pairsFor(array $userIds, ?array $courseIds = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $pairs = [];
        foreach (self::query($userIds, $courseIds)->get(['user_id', 'course_id']) as $row) {
            $pairs[$row->user_id.':'.$row->course_id] = true;
        }

        return $pairs;
    }
}
