<?php

namespace App\Support;

/**
 * Pass or fail for a quiz score (D-087, NEW2B-6232).
 *
 * The quiz's own pass score decides when it has one. A quiz without one
 * passes at half its full mark — the rule the legacy exams always used
 * (`UserExamService`). Before D-087 a rich quiz with no pass score stored
 * 'success' whatever the score, so the Dashboard showed "pass" for failed
 * attempts and a failed final quiz could issue a certificate.
 */
final class QuizVerdict
{
    public static function passes(float $score, ?float $passScore, float $maxScore): bool
    {
        if ($passScore !== null) {
            return $score >= $passScore;
        }

        return $maxScore > 0 && $score * 2 >= $maxScore;
    }

    /** The `user_exams.status` value for a score. */
    public static function status(float $score, ?float $passScore, float $maxScore): string
    {
        return self::passes($score, $passScore, $maxScore) ? 'success' : 'fail';
    }
}
