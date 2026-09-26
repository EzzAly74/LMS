<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\User;
use App\Models\UserCourseEvaluation;
use App\Notifications\CourseEvaluationDroppedNotification;
use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserCourseEvaluationService
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly AdminEvaluationReportService $reports,
    ) {}

    public function hasEvaluated(int $userId, int $courseId): bool
    {
        return UserCourseEvaluation::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->exists();
    }

    /** The learner's enrolment in the course, or null (their cohort is its group_id). */
    public function enrolment(int $userId, int $courseId): ?object
    {
        return DB::table('users_courses')->where('user_id', $userId)->where('course_id', $courseId)->first();
    }

    /**
     * The templates this learner is asked to answer for this course: those for
     * every course plus those written for this course, narrowed to their cohort
     * where a template names one (D-054).
     */
    public function getForm(Course $course, ?int $sectionId): Collection
    {
        return EvaluationCategory::query()
            ->forCourse($course->id, $sectionId)
            ->with(['evaluations' => fn ($q) => $q->orderBy('id')])
            ->orderBy('id')
            ->get();
    }

    /**
     * Submit the course evaluation - once per learner per course (Q-019).
     *
     * $questions: [ evaluation_id => answer, ... ]. Only questions of the
     * templates put to this learner are accepted; every required one must be
     * answered; a scale answer must be a whole number on its scale.
     *
     * Runs under a row lock on the learner's enrolment, so two submits sent
     * together cannot both pass the "already evaluated" check.
     */
    public function submit(User $user, Course $course, int $instructorId, array $questions): void
    {
        $dropped = null;

        DB::transaction(function () use ($user, $course, $instructorId, $questions, &$dropped) {
            $enrolment = DB::table('users_courses')
                ->where('user_id', $user->id)->where('course_id', $course->id)
                ->lockForUpdate()->first();

            if ($enrolment === null) {
                throw ValidationException::withMessages(['course' => __('messages.evaluation_not_enrolled')]);
            }
            if ($this->hasEvaluated($user->id, $course->id)) {
                throw ValidationException::withMessages(['course' => __('messages.already_evaluated')]);
            }

            $instructor = $course->instructors()->find($instructorId);
            if ($instructor === null) {
                throw ValidationException::withMessages(['instructor_id' => __('messages.evaluation_instructor_mismatch')]);
            }

            $form      = $this->getForm($course, $enrolment->group_id !== null ? (int) $enrolment->group_id : null);
            $answers   = $this->validatedAnswers($form, $questions);
            $scoreSql  = $this->reports->scoreExpression('uce');
            $before    = $this->courseScore($course->id, $scoreSql);
            $firstRow  = null;

            foreach ($answers as [$evaluation, $template, $answer]) {
                $row = UserCourseEvaluation::create([
                    'user_id'                  => $user->id,
                    'user_machine_code'        => $user->machine_code,
                    'user_department'          => $user->department_name,
                    'course_id'                => $course->id,
                    'course_name'              => $course->title,
                    'instructor_id'            => $instructor->id,
                    'instructor_name'          => $instructor->name,
                    'evaluation_category_id'   => $template->id,
                    'evaluation_category_name' => $template->name,
                    'evaluation_id'            => $evaluation->id,
                    'evaluation_title'         => $evaluation->title,
                    'evaluation_type'          => Evaluation::SCALE_MAX[$evaluation->type] ?? 0,
                    'answer'                   => $answer,
                ]);
                $firstRow ??= $row;
            }

            // Alert only on the crossing (Q-034): at or above the limit before
            // (or never evaluated), below it now. Re-alerting on every low
            // answer after that would bury the notification.
            $after     = $this->courseScore($course->id, $scoreSql);
            $threshold = $this->reports->passThreshold();
            if ($after !== null && $after < $threshold && ($before === null || $before >= $threshold)) {
                $dropped = $after;
            }

            // Completing an evaluation-based course earns its certificate.
            // Eligibility (course.certificate && is_evaluate) + dedup are
            // enforced inside CertificateService.
            if ($firstRow !== null) {
                $firstRow->setRelation('course', $course);
                $firstRow->setRelation('user', $user);
                $this->certificates->issueFromEvaluation($firstRow);
            }
        });

        if ($dropped !== null) {
            $this->notifyDrop($course, $dropped);
        }
    }

    /**
     * @return list<array{0: Evaluation, 1: EvaluationCategory, 2: string}>
     */
    private function validatedAnswers(Collection $form, array $questions): array
    {
        $errors = [];
        $out    = [];

        foreach ($form as $template) {
            foreach ($template->evaluations as $evaluation) {
                $raw    = $questions[$evaluation->id] ?? null;
                $answer = is_scalar($raw) ? trim((string) $raw) : '';
                $key    = "questions.{$evaluation->id}";

                if ($answer === '') {
                    if ($evaluation->is_required) {
                        $errors[$key] = __('messages.evaluation_answer_required');
                    }
                    continue;
                }

                $max = Evaluation::SCALE_MAX[$evaluation->type] ?? null;
                if ($max !== null) {
                    if (! ctype_digit($answer) || (int) $answer < 1 || (int) $answer > $max) {
                        $errors[$key] = __('messages.evaluation_answer_range', ['max' => $max]);
                        continue;
                    }
                    $answer = (string) (int) $answer;
                } elseif (mb_strlen($answer) > 2000) {
                    $errors[$key] = __('messages.evaluation_answer_too_long');
                    continue;
                }

                $out[] = [$evaluation, $template, $answer];
            }
        }

        // Answers to questions that were not put to this learner.
        $asked = $form->flatMap->evaluations->pluck('id')->all();
        foreach (array_keys($questions) as $id) {
            if (! in_array((int) $id, $asked, true)) {
                $errors["questions.{$id}"] = __('messages.evaluation_question_not_asked');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($out === []) {
            throw ValidationException::withMessages(['questions' => __('messages.evaluation_answer_required')]);
        }

        return $out;
    }

    private function courseScore(int $courseId, string $scoreSql): ?float
    {
        $score = DB::table('user_course_evaluations as uce')->where('uce.course_id', $courseId)->value(DB::raw($scoreSql));

        return $score !== null ? (float) $score : null;
    }

    /** Admins who can see evaluations (and super admins, who see everything), and the course's instructors. */
    private function notifyDrop(Course $course, float $score): void
    {
        $notification = new CourseEvaluationDroppedNotification($course, $score, $this->reports->passThreshold());

        $admins = Admin::query()
            ->where(fn ($q) => $q->permission('view-evaluations')->orWhereHas('roles', fn ($r) => $r->where('name', 'superAdmin')))
            ->get();
        foreach ($admins as $admin) {
            $admin->notify($notification);
        }
        foreach ($course->instructors as $instructor) {
            $instructor->notify($notification);
        }
    }
}
