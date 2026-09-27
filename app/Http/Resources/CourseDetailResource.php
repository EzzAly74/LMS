<?php

namespace App\Http\Resources;

use App\Models\Setting;
use App\Services\CertificatePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class CourseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Roll the stored `active` flag up against the cohort calendar.
        // The effective status is what we surface to clients ("active"
        // when a cohort is currently running, "upcoming" when one is
        // queued, "inactive" otherwise) so the badge tells the truth
        // without waiting for the nightly sync job to refresh stored
        // columns. See Course::effectiveStatus().
        $effectiveStatus = $this->resource->effectiveStatus();
        $effectiveActive = $effectiveStatus === 'active';

        // Per-course override for "Max per Cohort". Falls back to the
        // platform-wide `default_cohort_size` setting so the Course Settings
        // panel always shows a useful number instead of an em-dash.
        $maxLearners = $this->max_learners;
        if ($maxLearners === null) {
            $maxLearners = (int) (Setting::query()
                ->where('key', 'default_cohort_size')
                ->value('value') ?? 30);
        }

        // Read the pass percent through the policy rather than the settings
        // row directly, so this display can never drift from the threshold
        // actually enforced at issuance. Null when the rule this course
        // follows (general or its own, D-058) doesn't grade on score at all.
        $rule = app(CertificatePolicy::class)->forCourse($this->resource);
        $passPercent = $rule->requires(CertificatePolicy::METRIC_SCORE)
            ? $rule->minScore
            : null;
        $customRule = (bool) $this->certificate_custom_rule
            && in_array($this->certificate_mode, CertificatePolicy::BASES, true);

        return [
            'id'                 => $this->id,
            'title'              => [
                'en' => (string) ($this->getTranslation('title', 'en') ?? ''),
                'ar' => (string) ($this->getTranslation('title', 'ar') ?? ''),
            ],
            'description'        => [
                'en' => (string) ($this->getTranslation('description', 'en') ?? ''),
                'ar' => (string) ($this->getTranslation('description', 'ar') ?? ''),
            ],
            // Bilingual bullet lists (Overview tab + Add/Edit dialog).
            'what_students_will_learn' => [
                'en' => array_values((array) (($this->what_students_will_learn['en'] ?? []) ?: [])),
                'ar' => array_values((array) (($this->what_students_will_learn['ar'] ?? []) ?: [])),
            ],
            'requirements'       => [
                'en' => array_values((array) (($this->requirements['en'] ?? []) ?: [])),
                'ar' => array_values((array) (($this->requirements['ar'] ?? []) ?: [])),
            ],
            'course_type'        => $this->course_type,
            'category'           => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->getTranslation('name', app()->getLocale()),
            ]),
            'instructors'        => $this->whenLoaded('instructors',
                fn () => $this->instructors->map(fn ($i) => [
                    'id'    => $i->id,
                    'name'  => $i->getTranslation('name', app()->getLocale()),
                    'image' => $i->image ? $i->getFileUrl($i->image) : null,
                ]),
            ),
            'qualification_skills' => $this->whenLoaded('qualificationSkills',
                fn () => $this->qualificationSkills->map(fn ($s) => [
                    'id'   => $s->id,
                    'name' => $s->getTranslation('name', app()->getLocale()),
                ]),
            ),
            // Run sections through the resource so the cohort `status`
            // field reflects the live calendar (scheduled → active →
            // completed) rather than the stale persisted value.
            'sections'           => $this->whenLoaded('sections',
                fn () => CourseSectionResource::collection($this->sections),
            ),
            'exams'              => $this->whenLoaded('exams', fn () => $this->exams->map(fn ($e) => [
                'id'       => $e->id,
                'title'    => $e->getTranslation('title', app()->getLocale()),
                'degree'   => $e->degree,
                'is_final' => (bool) $e->is_final,
            ])),
            'image'              => $this->image ? $this->getFileUrl($this->image) : null,
            'intro_video'        => $this->intro_video,
            'hours'              => $this->hours,
            'max_learners'       => $maxLearners,
            'max_learners_override' => $this->max_learners,
            // Planned session count — read-only on the course; each new
            // cohort defaults to this value (Figma 321:7349 / 332:10708).
            'number_of_sessions' => $this->number_of_sessions !== null
                ? (int) $this->number_of_sessions
                : null,
            'language'           => $this->language,
            'level'              => $this->level,
            'price'              => $this->price,
            'currency'           => $this->currency,
            'certificate'              => (bool) $this->certificate,
            'certificate_pass_percent' => $this->certificate ? $passPercent : null,
            // The Add / Edit Course modal's rule: 'general' or the course's own
            // basis, with its own thresholds (null under the general rule).
            'certificate_rule'           => $customRule ? $this->certificate_mode : 'general',
            'certificate_min_attendance' => $customRule && $rule->requires(CertificatePolicy::METRIC_ATTENDANCE)
                ? $rule->minAttendance
                : null,
            'certificate_min_score'      => $customRule && $rule->requires(CertificatePolicy::METRIC_SCORE)
                ? $rule->minScore
                : null,
            'title_for_certificate'    => $this->getTranslation('title_for_certificate', app()->getLocale()),
            'active'             => $effectiveActive,
            'stored_active'      => (bool) $this->active,
            'for_public'         => (bool) $this->for_public,
            'is_evaluate'        => (bool) $this->is_evaluate,
            'outside_materials'  => (bool) $this->outside_materials,
            'allow_attendances'  => (bool) $this->allow_attendances,
            'created_at'         => $this->created_at?->format('Y-m-d'),
            'updated_at'         => $this->updated_at?->format('Y-m-d'),
            'course_type'        => $this->course_type,
            'type'               => $this->course_type,
            // Drives the badge in the Courses table + the Course
            // Settings card. Always derived — never stale.
            'status'             => $effectiveStatus,
            'users_count'        => $this->users_count ?? null,
            'enrolled_count'     => $this->users_count ?? 0,
            'cohorts_count'      => (int) ($this->cohorts_count ?? $this->sessions_count ?? 0),
            'instructor'         => $this->whenLoaded('instructors', function () {
                $first = $this->instructors->first();
                return $first ? [
                    'id'   => $first->id,
                    'name' => $first->getTranslation('name', app()->getLocale()),
                ] : null;
            }),
            'qualifications'     => $this->whenLoaded('qualificationSkills',
                fn () => $this->qualificationSkills->map(fn ($s) => [
                    'id'   => $s->id,
                    'name' => $s->getTranslation('name', app()->getLocale()),
                ]),
            ),

            // Learner-engagement metrics for the KPI cards. The evaluation
            // score replaced the star rating here (2026-09-26); null means
            // no learner has evaluated the course.
            'evaluation_score'       => $this->evaluation_score,
            'evaluation_submissions' => (int) ($this->evaluation_submissions ?? 0),
            'completion_percent'     => $this->resolveCompletionPercent(),
            // Course Details header and tab counts (Figma 2266:128869), set
            // by CourseController::show. "Active" = learners in progress (D-059);
            // the submission counts equal the Quizzes / Assignments tab totals.
            'in_progress_count'            => $this->in_progress_count,
            'modules_count'                => $this->modules_count,
            'quiz_submissions_count'       => $this->quiz_submissions_count,
            'assignment_submissions_count' => $this->assignment_submissions_count,
        ];
    }

    /**
     * Course-wide completion percent — average across every enrolled user's
     * progress through the course lectures. One aggregate query.
     */
    private function resolveCompletionPercent(): int
    {
        $totalLectures = (int) DB::table('course_lectures')
            ->where('course_id', $this->id)
            ->count();

        if ($totalLectures === 0) {
            return 0;
        }

        $totalUsers = (int) DB::table('users_courses')
            ->where('course_id', $this->id)
            ->count();

        if ($totalUsers === 0) {
            return 0;
        }

        $completed = (int) DB::table('user_lecture_progress')
            ->join('course_lectures', 'course_lectures.id', '=', 'user_lecture_progress.lecture_id')
            ->join('users_courses', function ($join) {
                $join->on('users_courses.user_id', '=', 'user_lecture_progress.user_id')
                     ->whereColumn('users_courses.course_id', 'course_lectures.course_id');
            })
            ->where('course_lectures.course_id', $this->id)
            ->where('user_lecture_progress.completed', true)
            ->count();

        $denominator = $totalLectures * $totalUsers;

        return $denominator > 0
            ? (int) floor(($completed * 100) / $denominator)
            : 0;
    }
}
