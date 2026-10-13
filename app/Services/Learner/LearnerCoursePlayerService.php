<?php

namespace App\Services\Learner;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UserCourseAssignment;
use App\Models\UserExam;
use App\Models\UserLectureProgress;
use App\Services\CertificateProjectionService;
use App\Services\LectureProgressService;
use App\Support\CourseCompletion;
use Illuminate\Support\Facades\DB;

/**
 * Composite payload backing the course-player workspace's sidebar: lectures
 * (video/document/article/link) and rich quizzes/assignments, grouped into
 * the "Week 1: CX Foundations"-style sections the Figma sidebar renders,
 * plus the persistent certificate-status header badge and overall module
 * progress.
 *
 * Grouping: lectures and rich exams both carry `section_id` → the
 * translatable `course_sections.name` (confirmed the real grouping field —
 * see LectureProgressService::getLectureProgress). Rich assignments have no
 * `section_id` of their own (only a session-level cohort_scope pivot, a
 * different concept), so they're placed in a trailing "Assessments" group
 * rather than guessing a week association the schema doesn't support.
 *
 * Reuses LectureProgressService::getLectureProgress and
 * CertificateProjectionService::projectForCourse rather than re-deriving
 * either — no forked business logic.
 */
class LearnerCoursePlayerService
{
    public function __construct(
        private readonly LectureProgressService $progress,
        private readonly CertificateProjectionService $certificates,
    ) {}

    public function outline(User $user, Course $course): array
    {
        $locale = app()->getLocale();

        $lectures = collect($this->progress->getLectureProgress($user->id, $course->id));

        // Only those made for every cohort or for the learner's (NEW2B-6255).
        $exams = CourseExam::where('course_id', $course->id)
            ->visibleTo((int) $user->id)
            ->get()
            ->filter(fn (CourseExam $exam) => $this->isRichExam($exam));

        $assignments = CourseAssignment::where('course_id', $course->id)
            ->visibleTo((int) $user->id)
            ->get()
            ->filter(fn (CourseAssignment $assignment) => $this->isRichAssignment($assignment));

        $userExamsByExamId = UserExam::where('user_id', $user->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->keyBy('exam_id');

        $userAssignmentsByAssignmentId = UserCourseAssignment::where('user_id', $user->id)
            ->whereIn('course_assignment_id', $assignments->pluck('id'))
            ->withCount(['answers as unscored_count' => fn ($a) => $a->whereNull('awarded_score')])
            ->get()
            ->keyBy('course_assignment_id');

        // Group by the learner's cohort schedule (D-079): a module sits under
        // the first session of their cohort whose "content" lists it.
        // Modules no session lists go to a trailing "Course content" group:
        // their section_id is a cohort, whose name ("First Group") means
        // nothing as a heading. Generated session titles are shown in the
        // reader's language and numbered by the schedule (SessionTitle). $order keeps sessions in
        // schedule order ahead of the assessments tail.
        $sessionOf = $this->firstSessionByLecture($user, $course);
        $groups = [];
        $order  = [];

        foreach ($lectures as $lecture) {
            $session = $sessionOf[(int) $lecture['lecture_id']] ?? null;
            if ($session !== null) {
                $label = \App\Support\SessionTitle::localize($session['title'], $session['number'], $locale) ?? $session['title'];
                $sort  = $session['number'];
            } else {
                $label = __('messages.course_player.general_content');
                $sort  = 9000;
            }
            $groups[$label][] = [
                'kind' => 'lecture',
                'id' => $lecture['lecture_id'],
                'title' => $lecture['title'],
                'content_type' => $lecture['content_type'],
                'completed' => $lecture['completed'],
            ];
            $order[$label] ??= $sort;
        }

        foreach ($exams as $exam) {
            $label = __('messages.course_player.assessments_group');

            $userExam = $userExamsByExamId->get($exam->id);

            $groups[$label][] = [
                'kind' => 'quiz',
                'id' => $exam->id,
                'title' => $exam->getTranslation('title', $locale),
                'content_type' => null,
                'completed' => $userExam?->submission_status === UserExam::SUBMISSION_SUBMITTED,
            ];
            $order[$label] ??= 9500;
        }

        foreach ($assignments as $assignment) {
            $label = __('messages.course_player.assessments_group');
            $userAssignment = $userAssignmentsByAssignmentId->get($assignment->id);

            // Sent: passed, failed, or waiting for a person's score. Only a
            // pass completes it (NEW2B-6277, D-090); a fail shows a cross.
            $result = null;
            if ($userAssignment?->submitted_at !== null) {
                $result = (int) $userAssignment->unscored_count > 0 ? 'pending'
                    : ((int) $userAssignment->total_score >= $assignment->effectivePassScore() ? 'passed' : 'failed');
            }

            $groups[$label][] = [
                'kind' => 'assignment',
                'id' => $assignment->id,
                'title' => $assignment->localizedTitle($locale),
                'content_type' => null,
                'completed' => $result === 'passed',
                'result' => $result,
            ];
            $order[$label] ??= 9999;
        }

        uksort($groups, fn ($a, $b) => ($order[$a] ?? 9999) <=> ($order[$b] ?? 9999));

        $weeks = $this->flagActiveItem($groups);

        $modulesTotal = $lectures->count();
        $modulesCompleted = $lectures->filter(fn ($l) => $l['completed'])->count();

        $certificateStatus = $this->certificates->projectForCourse($user, $course);

        return [
            'course_id' => $course->id,
            'course_title' => $course->getTranslation('title', $locale),
            'certificate_status' => $certificateStatus,
            'modules_completed' => $modulesCompleted,
            'modules_total' => $modulesTotal,
            'weeks' => $weeks,
            'completion' => $this->completion($user, $course, $weeks, $certificateStatus),
        ];
    }

    /**
     * The player's last step (Figma 2535:147143 / 2507:146778, NEW2B-5985):
     * whether the course is completed by the one project rule (D-082, D-086),
     * the learner's active certificate, and what is still open.
     *
     * @param  list<array{items: list<array{completed: bool}>}>  $weeks
     * @return array{completed: bool, certificate_offered: bool, certificate_id: int|null,
     *               evaluation_pending: bool, items_remaining: int}
     */
    private function completion(User $user, Course $course, array $weeks, array $certificateStatus = []): array
    {
        $completed = DB::table('courses')
            ->where('courses.id', $course->id)
            ->whereRaw(CourseCompletion::existsSql((string) (int) $user->id, 'courses.id'))
            ->exists();

        $certificateId = UserCertificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', UserCertificate::STATUS_ACTIVE)
            ->value('id');

        $evaluationPending = (bool) $course->is_evaluate
            && ! DB::table('user_course_evaluations')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->exists();

        $remaining = 0;
        foreach ($weeks as $week) {
            foreach ($week['items'] as $item) {
                $remaining += $item['completed'] ? 0 : 1;
            }
        }

        return [
            'completed'           => $completed,
            'certificate_offered' => (bool) $course->certificate,
            'certificate_id'      => $certificateId !== null ? (int) $certificateId : null,
            'evaluation_pending'  => $evaluationPending,
            'items_remaining'     => $remaining,
            'waiting_for'         => $completed ? [] : $this->waitingFor($user, $course, $remaining, $evaluationPending, $certificateStatus),
        ];
    }

    /**
     * What still stands between the learner and completing the course
     * (D-091), so the certificate step says it instead of "Items left: 0":
     * the cohort not started yet, content left, the final exam, the
     * evaluation, and the certificate rule's attendance and score.
     *
     * @param  array<string, mixed>  $certificateStatus  the outline's certificate_status
     * @return list<array<string, mixed>>
     */
    private function waitingFor(User $user, Course $course, int $remaining, bool $evaluationPending, array $certificateStatus): array
    {
        $waiting = [];
        $cohortId = DB::table('users_courses')->where('user_id', $user->id)->where('course_id', $course->id)->value('group_id');

        if ($cohortId !== null) {
            $first = DB::table('course_sessions')->where('section_id', $cohortId)
                ->selectRaw("MIN(TIMESTAMP(session_date, COALESCE(time_from, '00:00:00'))) as first_start")
                ->value('first_start');
            if ($first !== null && \Illuminate\Support\Carbon::parse($first)->isFuture()) {
                $waiting[] = ['reason' => 'starts', 'date' => \Illuminate\Support\Carbon::parse($first)->toDateString()];
            }
        }

        if ($remaining > 0) {
            $waiting[] = ['reason' => 'content', 'remaining' => $remaining];
        }

        $finalExam = CourseExam::where('course_id', $course->id)->where('is_final', true)->value('id');
        if ($finalExam !== null && ! UserExam::where('user_id', $user->id)->where('exam_id', $finalExam)->where('status', 'success')
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))->exists()) {
            $waiting[] = ['reason' => 'final_exam'];
        }

        if ($evaluationPending) {
            $waiting[] = ['reason' => 'evaluation'];
        }

        if ($course->certificate && ($certificateStatus['status'] ?? null) !== CertificateProjectionService::STATUS_EARNED) {
            $attendanceThreshold = $certificateStatus['attendance_threshold'] ?? null;
            if ($attendanceThreshold !== null) {
                // The sheet's session rows, as the certificate rule counts them (B-182).
                $rows = $cohortId !== null ? DB::table('course_sessions')->where('section_id', $cohortId)->count() : 0;
                $total = $rows > 0 ? $rows : (int) ($cohortId !== null
                    ? (DB::table('course_sections')->where('id', $cohortId)->value('number_of_sessions') ?? $course->number_of_sessions)
                    : $course->number_of_sessions);
                if ($total > 0) {
                    $needed = (int) ceil($attendanceThreshold * $total / 100);
                    $attended = (int) DB::table('attendances')->where('user_id', $user->id)->where('course_id', $course->id)
                        ->whereNotNull('session_id')->distinct()->count('session_id');
                    if ($attended < $needed) {
                        $waiting[] = ['reason' => 'attendance', 'attended' => $attended, 'needed' => $needed, 'total' => $total];
                    }
                }
            }

            $scoreThreshold = $certificateStatus['score_threshold'] ?? null;
            $score = $certificateStatus['score_percent'] ?? null;
            if ($scoreThreshold !== null && ($score === null || $score < $scoreThreshold)) {
                $waiting[] = ['reason' => 'score', 'threshold' => (int) $scoreThreshold];
            }
        }

        return $waiting;
    }

    /**
     * Full content for a single lecture — the outline's per-item payload is
     * metadata-only (title/content_type/completed) for sidebar rendering, so
     * the content viewer fetches the actual body/URL for the selected item
     * here rather than bloating every outline response with every lecture's
     * full content up front.
     */
    public function lecture(User $user, Course $course, CourseLecture $lecture): array
    {
        $locale = app()->getLocale();

        // The English article text / document file for an English reader,
        // else the Arabic (main) one every module has.
        $english = $locale === 'en';
        $file = $english && $lecture->content_type === 'document' && filled($lecture->video_en)
            ? $lecture->video_en
            : $lecture->video;
        $body = $english && filled($lecture->content_en) ? $lecture->content_en : $lecture->content;

        $contentUrl = match ($lecture->content_type) {
            'video', 'document' => $lecture->type === 'file' && $file
                ? $lecture->getFileUrl($file)
                : $file,
            'link' => $lecture->video,
            default => null,
        };

        $progress = UserLectureProgress::where('user_id', $user->id)
            ->where('lecture_id', $lecture->id)
            ->first();

        return [
            'id' => $lecture->id,
            'title' => $lecture->getTranslation('title', $locale),
            'description' => $lecture->getTranslation('instructions', $locale),
            'content_type' => $lecture->content_type,
            'content_url' => $contentUrl ?: null,
            'body' => $lecture->content_type === 'article' ? $body : null,
            'duration_minutes' => $lecture->duration_minutes,
            'require_completion' => (bool) $lecture->require_completion,
            'completed' => (bool) ($progress?->completed ?? false),
        ];
    }

    /** A quiz only qualifies for the rich learner flow once it has rich-authored questions. */
    /**
     * For each module, the first session of the learner's cohort that covers
     * it: its title and its 1-based number in the cohort's schedule. Empty
     * when the learner has no cohort on this course.
     *
     * @return array<int, array{title: string, number: int}>
     */
    private function firstSessionByLecture(User $user, Course $course): array
    {
        $cohortId = DB::table('users_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('group_id');
        if ($cohortId === null) {
            return [];
        }

        $sessions = DB::table('course_sessions')
            ->where('section_id', $cohortId)
            ->orderBy('session_date')->orderBy('time_from')->orderBy('id')
            ->get(['id', 'title'])
            ->values();
        if ($sessions->isEmpty()) {
            return [];
        }

        $numbers = $sessions->mapWithKeys(fn ($s, $i) => [(int) $s->id => $i + 1])->all();
        $titles  = $sessions->mapWithKeys(fn ($s) => [(int) $s->id => (string) $s->title])->all();

        $out = [];
        $links = DB::table('course_session_lectures')
            ->whereIn('session_id', array_keys($numbers))
            ->get(['session_id', 'lecture_id']);
        foreach ($links as $link) {
            $n = $numbers[(int) $link->session_id];
            $lectureId = (int) $link->lecture_id;
            if (! isset($out[$lectureId]) || $n < $out[$lectureId]['number']) {
                $out[$lectureId] = ['title' => $titles[(int) $link->session_id], 'number' => $n];
            }
        }

        return $out;
    }

    private function isRichExam(CourseExam $exam): bool
    {
        return CourseExamQuestion::where('course_exam_id', $exam->id)->whereNotNull('question_en')->exists();
    }

    private function isRichAssignment(CourseAssignment $assignment): bool
    {
        return CourseAssignmentQuestion::where('course_assignment_id', $assignment->id)->exists();
    }

    /**
     * Marks the first incomplete item across the whole flattened playlist as
     * `active` — the sidebar's current-position indicator and the
     * dashboard's "Continue Learning" resume target.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     * @return array<int, array{label: string, items: array}>
     */
    private function flagActiveItem(array $groups): array
    {
        $activeAssigned = false;

        $weeks = collect($groups)->map(function (array $items, string $label) use (&$activeAssigned) {
            $items = array_map(function (array $item) use (&$activeAssigned) {
                // A sent assignment is not "next", whatever its result.
                $isActive = ! $activeAssigned && ! $item['completed'] && ($item['result'] ?? null) === null;
                if ($isActive) {
                    $activeAssigned = true;
                }
                $item['active'] = $isActive;

                return $item;
            }, $items);

            return ['label' => $label, 'items' => $items];
        })->values();

        return $weeks->all();
    }
}
