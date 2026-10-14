<?php

namespace App\Support;

use App\Models\Course;
use App\Services\CertificatePolicy;
use Illuminate\Support\Facades\DB;

/**
 * D-095: once a learner is enrolled, a course keeps the terms they enrolled
 * on. Three things are fixed from the first enrolment:
 *
 *  - the course type (how it is delivered: modules, sessions, an external link);
 *  - the certificate rule and its thresholds (who earns the certificate, D-094);
 *  - the qualifications it counts towards (job-title compliance, D-093).
 *
 * Changing any of them would rewrite progress, eligibility or compliance for
 * learners already enrolled or finished. Everything else (titles, texts,
 * image, category, level, instructor, lists) stays editable.
 *
 * The Dashboard disables these fields from `locked_fields`; the request
 * rejects a change here, so no other client can bypass it.
 */
final class CourseEditLock
{
    public const COURSE_TYPE    = 'course_type';
    public const CERTIFICATE    = 'certificate_rule';
    public const QUALIFICATIONS = 'qualification_skill_ids';

    /** In the order the form shows them. */
    // Qualifications are not locked: an edit applies from the next cohort,
    // the cohorts under way keep theirs (D-106, NEW2B-6049).
    public const FIELDS = [self::COURSE_TYPE, self::CERTIFICATE];

    public static function applies(Course $course): bool
    {
        return DB::table('users_courses')->where('course_id', $course->getKey())->exists();
    }

    /** @return list<string> */
    public static function lockedFields(Course $course): array
    {
        return self::applies($course) ? self::FIELDS : [];
    }

    /**
     * The locked values a request would change, as request key => message.
     * A field that is not sent, or is sent unchanged, is fine.
     *
     * @param  array<string, mixed>  $input  the prepared request input
     * @return array<string, string>
     */
    public static function violations(Course $course, array $input): array
    {
        if (!self::applies($course)) {
            return [];
        }

        $message = __('messages.course_locked_field');
        $out = [];

        if (array_key_exists('course_type', $input) && (string) $input['course_type'] !== (string) $course->course_type) {
            $out['course_type'] = $message;
        }

        if (array_key_exists('certificate_rule', $input)) {
            foreach (self::certificateChanges($course, $input) as $key) {
                $out[$key] = $message;
            }
        }

        return $out;
    }

    /**
     * The rule as the form shows it (CourseDetailResource): 'general', or the
     * course's own basis with its effective thresholds.
     *
     * @return list<string> the request keys that differ
     */
    private static function certificateChanges(Course $course, array $input): array
    {
        $custom = $course->certificate_custom_rule && in_array($course->certificate_mode, CertificatePolicy::BASES, true);
        $current = $custom ? $course->certificate_mode : CertificatePolicy::RULE_GENERAL;
        if ((string) $input['certificate_rule'] !== $current) {
            return ['certificate_rule'];
        }
        if (!$custom) {
            return [];
        }

        $rule = app(CertificatePolicy::class)->forCourse($course);
        $changed = [];
        if ($rule->requires(CertificatePolicy::METRIC_ATTENDANCE)
            && (int) ($input['certificate_min_attendance'] ?? 0) !== $rule->minAttendance) {
            $changed[] = 'certificate_min_attendance';
        }
        if ($rule->requires(CertificatePolicy::METRIC_SCORE)
            && (int) ($input['certificate_min_score'] ?? 0) !== $rule->minScore) {
            $changed[] = 'certificate_min_score';
        }

        return $changed;
    }

    /** @return list<int> sorted, unique; '' / null (an emptied list) is none */
    private static function ids(mixed $value): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $value, fn ($v) => $v !== '' && $v !== null))));
        sort($ids);

        return $ids;
    }
}
