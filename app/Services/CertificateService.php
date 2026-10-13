<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UserCourseEvaluation;
use App\Models\UserExam;
use App\Repositories\Contracts\UserCertificateRepositoryInterface;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * First-class Certificate domain service (2026 redesign).
 *
 * A certificate is a standalone business document, NOT a computed view
 * of exam/evaluation completion. All issuance, numbering, deduplication
 * and revocation flow through this single service so the rules live in
 * exactly one place (never scattered across controllers).
 *
 * Issuance is a completion TRIGGER plus the certificate RULE:
 *
 *   Trigger A) final-exam pass  - course.certificate && !course.is_evaluate
 *                                 && exam.is_final && user_exam.status='success'
 *   Trigger B) evaluation done  - course.certificate &&  course.is_evaluate
 *   Trigger C) attendance       - measurement is the trigger (no event)
 *
 *   Rule) App\Services\CertificatePolicy, read from Platform Config, is the
 *         single source of truth for the thresholds every trigger must clear.
 *
 * Backfill is deliberately exempt from the rule — see {@see backfillFromExam}.
 *
 * Invariant: one learner + one course = one ACTIVE certificate. Enforced here
 * inside a transaction AND by a unique index (see migration
 * 2026_08_02_110000_add_unique_active_certificate_index).
 */
class CertificateService
{
    public function __construct(
        private readonly UserCertificateRepositoryInterface $repo,
        private readonly CertificateProjectionService $projection,
    ) {}

    /* ================================================================ *
     |  Issuance                                                        |
     * ================================================================ */

    /**
     * Issue a certificate for a passed final exam. Returns the existing
     * active certificate (idempotent) or null when the exam is not
     * certificate-eligible or the learner does not clear the configured rule.
     */
    public function issueFromExam(UserExam $exam): ?UserCertificate
    {
        // The exam may not be this course's completion event (an evaluated
        // course), or not the last missing piece: evaluate() decides (B-179).
        return $this->fromExam($exam, applyPolicy: true) ?? $this->evaluateFor($exam->user, $exam->course);
    }

    /**
     * Grandfathered variant used by `certificates:migrate-existing`.
     *
     * Historical completions were earned under the rules in force at the
     * time, and the production dashboard has been displaying them ever
     * since. Re-judging them against today's Platform Config would silently
     * delete certificates learners already hold, so the backfill materialises
     * exactly the set the legacy triggers describe and skips the rule.
     */
    public function backfillFromExam(UserExam $exam): ?UserCertificate
    {
        return $this->fromExam($exam, applyPolicy: false);
    }

    private function fromExam(UserExam $exam, bool $applyPolicy): ?UserCertificate
    {
        $exam->loadMissing(['course', 'exam', 'user']);

        $course  = $exam->course;
        $examDef = $exam->exam;

        if (!$course || !$examDef) {
            return null;
        }
        // Every course issues a certificate (D-094); an evaluated course's
        // event is its evaluation, not the exam.
        if ($course->is_evaluate) {
            return null;
        }
        if (!$examDef->is_final || $exam->status !== 'success') {
            return null;
        }
        if ($applyPolicy && $exam->user && !$this->projection->satisfiesPolicy($exam->user, $course)) {
            return null;
        }
        if ($applyPolicy && $this->wasRevoked((int) $exam->user_id, (int) $course->id)) {
            return null;
        }
        // Only a sent attempt is a pass (B-162).
        if (! UserExam::query()->whereKey($exam->id)->whereRaw(\App\Support\CourseCompletion::submittedExamSql('user_exams'))->exists()) {
            return null;
        }

        return $this->issue(
            userId: (int) $exam->user_id,
            courseId: (int) $course->id,
            sourceType: UserCertificate::SOURCE_EXAM,
            sourceId: (int) $exam->id,
            // When it was sent, not when it was opened.
            issuedAt: $exam->submitted_at ?? $exam->created_at ?? now(),
            issuedBy: null,
            metadata: $this->snapshot($exam->user, $course, [
                'source'            => UserCertificate::SOURCE_EXAM,
                'exam_total_degree' => $examDef->degree,
                'user_degree'       => $exam->user_degree,
            ]),
        );
    }

    /**
     * Issue a certificate for a completed evaluation-based course.
     * Idempotent; returns null when the course is not certificate-eligible
     * or the learner does not clear the configured rule.
     */
    public function issueFromEvaluation(UserCourseEvaluation $evaluation): ?UserCertificate
    {
        return $this->fromEvaluation($evaluation, applyPolicy: true) ?? $this->evaluateFor($evaluation->user, $evaluation->course);
    }

    private function evaluateFor(?User $user, ?Course $course): ?UserCertificate
    {
        return $user !== null && $course !== null ? $this->evaluate($user, $course) : null;
    }

    /**
     * An admin revoked this learner's certificate for the course: no trigger
     * mints a new one by itself (B-180, D-091); an admin issues it again.
     */
    private function wasRevoked(int $userId, int $courseId): bool
    {
        return UserCertificate::query()->where('user_id', $userId)->where('course_id', $courseId)
            ->where('status', UserCertificate::STATUS_REVOKED)->exists();
    }

    /** Grandfathered variant for the backfill — see {@see backfillFromExam}. */
    public function backfillFromEvaluation(UserCourseEvaluation $evaluation): ?UserCertificate
    {
        return $this->fromEvaluation($evaluation, applyPolicy: false);
    }

    private function fromEvaluation(UserCourseEvaluation $evaluation, bool $applyPolicy): ?UserCertificate
    {
        $evaluation->loadMissing(['course', 'user']);

        $course = $evaluation->course;

        if (!$course) {
            return null;
        }
        if (!$course->is_evaluate) {
            return null;
        }
        if ($applyPolicy && $evaluation->user && !$this->projection->satisfiesPolicy($evaluation->user, $course)) {
            return null;
        }
        if ($applyPolicy && $this->wasRevoked((int) $evaluation->user_id, (int) $course->id)) {
            return null;
        }

        return $this->issue(
            userId: (int) $evaluation->user_id,
            courseId: (int) $course->id,
            sourceType: UserCertificate::SOURCE_EVALUATION,
            sourceId: (int) $evaluation->id,
            issuedAt: $evaluation->created_at ?? now(),
            issuedBy: null,
            metadata: $this->snapshot($evaluation->user, $course, [
                'source' => UserCertificate::SOURCE_EVALUATION,
            ]),
        );
    }

    /**
     * Issue a certificate for a session/offline course earned through
     * attendance (and/or score, per the Platform Config basis). Gated on the
     * course offering a certificate AND the learner meeting every configured
     * threshold. Idempotent; returns null when not yet eligible.
     *
     * Unlike the exam/evaluation paths there is no completion event here —
     * the measurement IS the trigger — so this requires real measured
     * evidence (see CertificateProjectionService::meetsIssuanceCriteria).
     * A course that cannot report attendance never mints from this path.
     *
     * This is the path that lets instructor-led / attendance-based courses
     * mint a real certificate — previously impossible, since issuance only
     * ever fired from exam pass / evaluation submit.
     */
    public function issueFromAttendance(User $user, Course $course): ?UserCertificate
    {
        // Attendance alone no longer skips the course's own completion event
        // (final exam, evaluation or content); evaluate() checks both (D-091).
        return $this->evaluate($user, $course);
    }

    /**
     * Issue the certificate the moment the learner has everything it needs,
     * whichever came last (D-091): the course's completion event AND the
     * certificate rule. Every trigger calls this (a module completed,
     * attendance recorded, a quiz passed, an evaluation sent), so a learner
     * who finishes the content before the sessions are held still receives
     * the certificate once their attendance meets the rule.
     *
     * The completion event is the evaluation (evaluated course), else a
     * submitted pass of the final exam (course with one), else every module
     * of the learner's cohort sheet (D-089); a course with no modules at all
     * is earned by the rule alone. Idempotent; null while not yet eligible.
     */
    public function evaluate(User $user, Course $course): ?UserCertificate
    {
        $held = UserCertificate::query()->where('user_id', $user->id)->where('course_id', $course->id)->active()->first();
        if ($held !== null) {
            return $held;
        }
        if ($this->wasRevoked((int) $user->id, (int) $course->id) || ! $this->projection->meetsIssuanceCriteria($user, $course)) {
            return null;
        }

        if ($course->is_evaluate) {
            $evaluation = UserCourseEvaluation::query()
                ->where('user_id', $user->id)->where('course_id', $course->id)
                ->orderBy('id')->first();

            if ($evaluation === null) {
                return null;
            }
            // The final exam, when the course has one, is part of the rule's
            // score; the evaluation is the completion event.
            return $this->fromEvaluation($evaluation, applyPolicy: false);
        }

        $finalExamId = DB::table('course_exams')->where('course_id', $course->id)->where('is_final', true)->value('id');
        if ($finalExamId !== null) {
            $pass = UserExam::query()
                ->where('user_id', $user->id)->where('exam_id', $finalExamId)->where('status', 'success')
                ->whereRaw(\App\Support\CourseCompletion::submittedExamSql('user_exams'))
                ->orderBy('id')->first();

            return $pass ? $this->fromExam($pass, applyPolicy: false) : null;
        }

        $lectureIds = \App\Support\CohortModules::lectureIds((int) $user->id, (int) $course->id);
        if ($lectureIds !== []) {
            $done = DB::table('user_lecture_progress')
                ->where('user_id', $user->id)->whereIn('lecture_id', $lectureIds)->where('completed', true)
                ->count();
            if ($done < count($lectureIds)) {
                return null;
            }
        }

        $source = $lectureIds !== [] ? UserCertificate::SOURCE_CONTENT : UserCertificate::SOURCE_ATTENDANCE;

        return $this->issue(
            userId: (int) $user->id,
            courseId: (int) $course->id,
            sourceType: $source,
            sourceId: null,
            issuedAt: now(),
            issuedBy: null,
            metadata: $this->snapshot($user, $course, ['source' => $source]),
        );
    }

    /**
     * Issue a certificate when the learner has completed every content item
     * of a course whose own completion event is the content itself: it offers
     * a certificate and has no final exam and no evaluation (those keep their
     * own trigger, so finishing the content never skips them). The certificate
     * rule must be met with measured evidence (NEW2B-6205).
     * Idempotent; returns null when not eligible.
     */
    public function issueFromContent(User $user, Course $course): ?UserCertificate
    {
        if (! self::contentCompletes($course)) {
            return null;
        }

        // The rule (Platform Config, or the course's own) must be met with
        // real evidence: finishing the content is no proof of attendance or
        // score by itself (human, 2026-10-11). evaluate() checks both.
        return $this->evaluate($user, $course);
    }

    /** Is finishing the content this course's completion event? */
    public static function contentCompletes(Course $course): bool
    {
        return ! $course->is_evaluate
            && ! DB::table('course_exams')->where('course_id', $course->id)->where('is_final', true)->exists();
    }

    /* ================================================================ *
     |  Lifecycle                                                       |
     * ================================================================ */

    public function revoke(UserCertificate $certificate, ?int $revokedBy = null): UserCertificate
    {
        if ($certificate->isRevoked()) {
            return $certificate;
        }

        $certificate->update([
            'status'     => UserCertificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $revokedBy,
        ]);

        return $certificate->refresh();
    }

    public function findById(int $id): ?UserCertificate
    {
        return $this->repo->findById($id);
    }

    /* ================================================================ *
     |  Legacy read API (kept for /api/v1/certificates) � DB-backed     |
     * ================================================================ */

    public function paginate(int $perPage = 20, ?int $courseId = null): LengthAwarePaginator
    {
        $paginator = $this->repo->paginateAdmin($perPage, request()->input('search'), $courseId);
        $paginator->getCollection()->transform(fn (UserCertificate $c) => $this->toArray($c));

        return $paginator;
    }

    /** @return array<int, array<string, mixed>> */
    public function findByCourse(int $courseId): array
    {
        return $this->repo->paginateAdmin(1000, null, $courseId)
            ->getCollection()
            ->map(fn (UserCertificate $c) => $this->toArray($c))
            ->all();
    }

    /* ================================================================ *
     |  Internals                                                       |
     * ================================================================ */

    /**
     * Core issuance routine � transactional, idempotent (one active
     * certificate per learner+course), and number-safe under concurrency.
     */
    private function issue(
        int $userId,
        int $courseId,
        string $sourceType,
        ?int $sourceId,
        DateTimeInterface $issuedAt,
        ?int $issuedBy,
        array $metadata,
    ): UserCertificate {
        return DB::transaction(function () use ($userId, $courseId, $sourceType, $sourceId, $issuedAt, $issuedBy, $metadata) {
            $existing = $this->repo->findActiveByUserAndCourse($userId, $courseId);
            if ($existing) {
                return $existing;
            }

            $number = $this->generateNumber((int) $issuedAt->format('Y'));

            return $this->repo->create([
                'user_id'            => $userId,
                'course_id'          => $courseId,
                'source_type'        => $sourceType,
                'source_id'          => $sourceId,
                'certificate_number' => $number,
                'status'             => UserCertificate::STATUS_ACTIVE,
                'issued_at'          => $issuedAt,
                'issued_by'          => $issuedBy,
                'metadata'           => $metadata,
            ]);
        });
    }

    /** Sequential, per-year, zero-padded: CERT-2026-000001. */
    private function generateNumber(int $year): string
    {
        $latest = $this->repo->latestNumberForYear($year);

        $seq = 0;
        if ($latest !== null && preg_match('/CERT-'.$year.'-(\d+)/', $latest, $m)) {
            $seq = (int) $m[1];
        }

        return sprintf('CERT-%d-%06d', $year, $seq + 1);
    }

    /**
     * Immutable snapshot of the learner + course identity at issuance
     * time, so the historical document never shifts if HR/course data
     * changes later.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function snapshot(?User $user, Course $course, array $extra = []): array
    {
        return array_merge([
            'learner_name'         => $user?->name,
            'learner_machine_code' => $user?->machine_code,
            'learner_department'   => $user?->department_name,
            'course_title'         => [
                'en' => $course->getTranslation('title_for_certificate', 'en') ?: $course->getTranslation('title', 'en'),
                'ar' => $course->getTranslation('title_for_certificate', 'ar') ?: $course->getTranslation('title', 'ar'),
            ],
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function toArray(UserCertificate $certificate): array
    {
        $user   = $certificate->user;
        $course = $certificate->course;

        return [
            'id'                 => (int) $certificate->id,
            'uuid'               => $certificate->uuid,
            'certificate_number' => $certificate->certificate_number,
            'status'             => $certificate->status,
            'type'               => $certificate->source_type,
            'user'               => [
                'id'              => $user?->id,
                'name'            => $user?->name,
                'machine_code'    => $user?->machine_code,
                'department_name' => $user?->department_name,
            ],
            'course'             => [
                'id'                    => $course?->id,
                'title'                 => $course?->getTranslation('title', app()->getLocale()),
                'title_for_certificate' => $course?->getTranslation('title_for_certificate', app()->getLocale()),
            ],
            'issued_at'          => optional($certificate->issued_at)->format('Y-m-d H:i:s'),
            'created_at'         => optional($certificate->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
