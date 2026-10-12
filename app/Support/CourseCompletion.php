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
 * ── The rule (D-082) ─────────────────────────────────────────────────────────
 * A course that offers a certificate is completed when the learner holds an
 * ACTIVE certificate for it. Issuance already enforces the certificate rule
 * (D-084), so a revoked certificate is not a completion.
 *
 * A course without a certificate is completed by any of:
 *   - every content module (lecture) of the course completed;
 *   - a SUBMITTED, passing final exam (when the course is not evaluated);
 *   - the course evaluation submitted (when the course is evaluated).
 *
 * "Submitted" matters: an opened rich quiz attempt is a row too, and it
 * carries the column default status 'success' (B-162); see submittedExamSql().
 *
 * The completion moment (`completed_at`) is the earliest signal for the pair.
 */
final class CourseCompletion
{
    /** `user_exams` rows (alias `ue`) that are a submitted pass. */
    private const PASSED_EXAM_SQL = "ue.status = 'success' AND ";

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
        $passed = self::PASSED_EXAM_SQL.self::submittedExamSql('ue');

        return "(
            EXISTS (
                SELECT 1 FROM user_certificates ccert
                WHERE ccert.user_id = {$userColumn}
                  AND ccert.course_id = {$courseColumn}
                  AND ccert.status = 'active'
            )
            OR EXISTS (
                SELECT 1 FROM courses ccrs
                WHERE ccrs.id = {$courseColumn}
                  AND ccrs.certificate = 0
                  AND (
                    (ccrs.is_evaluate = 0 AND EXISTS (
                        SELECT 1 FROM user_exams ue
                        JOIN course_exams cex ON cex.id = ue.exam_id
                        WHERE ue.user_id = {$userColumn}
                          AND ue.course_id = {$courseColumn}
                          AND cex.is_final = 1
                          AND {$passed}
                    ))
                    OR (ccrs.is_evaluate = 1 AND EXISTS (
                        SELECT 1 FROM user_course_evaluations cev
                        WHERE cev.user_id = {$userColumn}
                          AND cev.course_id = {$courseColumn}
                    ))
                    OR (
                        EXISTS (SELECT 1 FROM course_lectures clx WHERE clx.course_id = {$courseColumn})
                        AND NOT EXISTS (
                            SELECT 1 FROM course_lectures cly
                            WHERE cly.course_id = {$courseColumn}
                              AND NOT EXISTS (
                                SELECT 1 FROM user_lecture_progress ulp
                                WHERE ulp.lecture_id = cly.id
                                  AND ulp.user_id = {$userColumn}
                                  AND ulp.completed = 1
                              )
                        )
                    )
                  )
            )
        )";
    }

    /**
     * Completed (user_id, course_id) pairs with the moment completion happened.
     *
     * @param  list<int>|null  $userIds    limit to these learners (null: all)
     * @param  list<int>|null  $courseIds  limit to these courses (null: all)
     */
    public static function query(?array $userIds = null, ?array $courseIds = null): Builder
    {
        $scope = static function (Builder $q, string $userCol, string $courseCol) use ($userIds, $courseIds): Builder {
            return $q
                ->when($userIds !== null, fn (Builder $b) => $b->whereIn($userCol, $userIds ?: [0]))
                ->when($courseIds !== null, fn (Builder $b) => $b->whereIn($courseCol, $courseIds ?: [0]));
        };

        // Courses with a certificate: an active certificate.
        $certificates = $scope(DB::table('user_certificates as ccert')
            ->where('ccert.status', 'active')
            ->selectRaw('ccert.user_id, ccert.course_id, COALESCE(ccert.issued_at, ccert.created_at) AS done_at'),
            'ccert.user_id', 'ccert.course_id');

        // Courses without a certificate, not evaluated: a submitted, passing final exam.
        $exams = $scope(DB::table('user_exams as ue')
            ->join('course_exams as cex', 'cex.id', '=', 'ue.exam_id')
            ->join('courses as ccrs', 'ccrs.id', '=', 'ue.course_id')
            ->where('cex.is_final', true)
            ->where('ccrs.certificate', false)
            ->where('ccrs.is_evaluate', false)
            ->whereRaw(self::PASSED_EXAM_SQL.self::submittedExamSql('ue'))
            ->selectRaw('ue.user_id, ue.course_id, COALESCE(ue.submitted_at, ue.updated_at) AS done_at'),
            'ue.user_id', 'ue.course_id');

        // Evaluated courses without a certificate: the evaluation submitted.
        $evaluations = $scope(DB::table('user_course_evaluations as cev')
            ->join('courses as ccrs', 'ccrs.id', '=', 'cev.course_id')
            ->where('ccrs.is_evaluate', true)
            ->where('ccrs.certificate', false)
            ->selectRaw('cev.user_id, cev.course_id, MIN(cev.created_at) AS done_at')
            ->groupBy('cev.user_id', 'cev.course_id'),
            'cev.user_id', 'cev.course_id');

        // Courses without a certificate: every content module completed.
        $content = $scope(DB::table('user_lecture_progress as ulp')
            ->join('course_lectures as cl', 'cl.id', '=', 'ulp.lecture_id')
            ->join('courses as ccrs', 'ccrs.id', '=', 'cl.course_id')
            ->where('ulp.completed', true)
            ->where('ccrs.certificate', false)
            ->selectRaw('ulp.user_id, cl.course_id, MAX(ulp.updated_at) AS done_at')
            ->groupBy('ulp.user_id', 'cl.course_id')
            ->havingRaw('COUNT(DISTINCT cl.id) = (SELECT COUNT(*) FROM course_lectures clt WHERE clt.course_id = cl.course_id)'),
            'ulp.user_id', 'cl.course_id');

        $signals = $certificates->unionAll($exams)->unionAll($evaluations)->unionAll($content);

        return DB::query()
            ->fromSub($signals, 'signals')
            ->selectRaw('user_id, course_id, MIN(done_at) AS completed_at')
            ->groupBy('user_id', 'course_id');
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
