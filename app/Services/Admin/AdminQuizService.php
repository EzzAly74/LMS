<?php

namespace App\Services\Admin;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseExamCohort;
use App\Models\CourseExamQuestion;
use App\Models\User;
use App\Models\UserExam;
use App\Services\CertificateService;
use App\Support\CourseInstructors;
use App\Support\PersonName;
use App\Support\QuizVerdict;
use App\Models\UserExamAnswer;
use App\Support\Notifications\CourseContentNotifier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service backing the rich (question-based) admin Quiz workflow.
 *
 * This service is purely additive. The legacy MCQ-only flow served by
 * `QuizController` and the section-bound exam logic continue to work
 * unchanged — they simply ignore the new columns / pivot table.
 */
class AdminQuizService
{
    public function __construct(private readonly LearnerCohorts $cohorts) {}

    /* ------------------------------------------------------------------ *
     |  QUIZ CRUD                                                         |
     * ------------------------------------------------------------------ */

    public function paginate(
        ?int $courseId,
        ?string $search,
        ?string $status,
        int $perPage = 20
    ): LengthAwarePaginator {
        return CourseExam::query()
            ->with(['course:id,title', 'cohorts.cohort:id,name'])
            ->withCount(['richQuestions as rich_questions_count'])
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('course_exam_questions')
                    ->whereColumn('course_exam_questions.course_exam_id', 'course_exams.id');
                    // NB: intentionally NOT restricted to rich (`question_en`)
                    // questions. Legacy exams store the prompt in `question`
                    // only; requiring `question_en` hid every existing quiz
                    // from the admin dashboard (all live data is legacy).
            })
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($status, fn ($q) => $q->where('status', $status))
            // Its title or its course's name (NEW2B-6357).
            ->when($search, fn ($q) => \App\Support\AssessmentSearch::itemOrCourse($q, $search))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function summary(): array
    {
        $quizzes = CourseExam::query()
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('course_exam_questions')
                    ->whereColumn('course_exam_questions.course_exam_id', 'course_exams.id');
                    // NB: intentionally NOT restricted to rich (`question_en`)
                    // questions. Legacy exams store the prompt in `question`
                    // only; requiring `question_en` hid every existing quiz
                    // from the admin dashboard (all live data is legacy).
            });

        return [
            'quizzes_count' => (clone $quizzes)->count(),
            'courses_count' => (clone $quizzes)->distinct('course_id')->count('course_id'),
        ];
    }

    public function listMinimal(?string $search, int $limit = 200): array
    {
        return CourseExam::query()
            ->select(['id', 'title', 'title_ar', 'course_id', 'status'])
            ->with('course:id,title')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('course_exam_questions')
                    ->whereColumn('course_exam_questions.course_exam_id', 'course_exams.id');
                    // NB: intentionally NOT restricted to rich (`question_en`)
                    // questions. Legacy exams store the prompt in `question`
                    // only; requiring `question_en` hid every existing quiz
                    // from the admin dashboard (all live data is legacy).
            })
            // Its title or its course's name (NEW2B-6357).
            ->when($search, fn ($q) => \App\Support\AssessmentSearch::itemOrCourse($q, $search))
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn ($q) => [
                'id'           => $q->id,
                'title'        => $q->title,
                'title_ar'     => $q->title_ar,
                'course_id'    => $q->course_id,
                'course_title' => optional($q->course)->title,
                'status'       => $q->status,
            ])
            ->all();
    }

    public function show(int $id): CourseExam
    {
        return CourseExam::query()
            ->with([
                'course:id,title',
                'creator:id,name,name_ar',
                // B-136: legacy options / answer key live in course_exam_question_answers.
                'richQuestions.answers',
                'cohorts.cohort:id,name',
            ])
            ->findOrFail($id);
    }

    public function create(array $data, ?Authenticatable $creator): CourseExam
    {
        return DB::transaction(function () use ($data, $creator) {
            /** @var CourseExam $quiz */
            $quiz = CourseExam::query()->create([
                'course_id'       => $data['course_id'],
                'section_id'      => null,
                'title'           => $data['title'],
                'title_ar'        => $data['title_ar'] ?? null,
                'instructions_en' => $data['instructions_en'] ?? null,
                'instructions_ar' => $data['instructions_ar'] ?? null,
                'due_date'        => $data['due_date'] ?? null,
                'cohort_scope'    => $data['cohort_scope'],
                'pass_score'      => $data['pass_score'] ?? null,
                'status'          => $data['status'] ?? 'draft',
                'type'            => $data['type'] ?? null,
                'created_by'      => $creator?->id,
                'total_score'     => $this->sumQuestionScores($data['questions'] ?? []),
                // Legacy column requirements:
                'degree'          => $this->sumQuestionScores($data['questions'] ?? []),
                'duration'        => 60,
            ]);

            $this->syncQuestions($quiz, $data['questions'] ?? []);
            $this->syncCohorts($quiz, $data['cohort_scope'], $data['cohort_ids'] ?? []);

            if ($quiz->status === 'active') {
                $this->announce($quiz);
            }

            return $this->show($quiz->id);
        });
    }

    public function update(CourseExam $quiz, array $data): CourseExam
    {
        return DB::transaction(function () use ($quiz, $data) {
            $total = $this->sumQuestionScores($data['questions'] ?? []);
            $wasActive = $quiz->status === 'active';

            $quiz->update([
                'course_id'       => $data['course_id'],
                'title'           => $data['title'],
                'title_ar'        => $data['title_ar'] ?? null,
                'instructions_en' => $data['instructions_en'] ?? null,
                'instructions_ar' => $data['instructions_ar'] ?? null,
                'due_date'        => $data['due_date'] ?? null,
                'cohort_scope'    => $data['cohort_scope'],
                'pass_score'      => $data['pass_score'] ?? null,
                'status'          => $data['status'] ?? $quiz->status,
                'type'            => $data['type'] ?? null,
                'total_score'     => $total,
                'degree'          => $total,
            ]);

            $this->syncQuestions($quiz, $data['questions'] ?? []);
            $this->syncCohorts($quiz, $data['cohort_scope'], $data['cohort_ids'] ?? []);

            // A draft that is published now reaches the learners (NEW2B-5803).
            if (! $wasActive && $quiz->status === 'active') {
                $this->announce($quiz);
            }

            return $this->show($quiz->id);
        });
    }

    /** Enrolled learners hear about a published quiz (NEW2B-5803). */
    private function announce(CourseExam $quiz): void
    {
        $course = Course::query()->find($quiz->course_id);
        if ($course === null) {
            return;
        }
        app(CourseContentNotifier::class)->added($course, 'quiz', [
            'en' => (string) $quiz->getTranslation('title', 'en', false),
            'ar' => (string) ($quiz->title_ar ?: $quiz->getTranslation('title', 'ar', false)),
        ], (int) $quiz->id);
    }

    public function delete(CourseExam $quiz): void
    {
        DB::transaction(function () use ($quiz) {
            CourseExamCohort::where('course_exam_id', $quiz->id)->delete();
            // The legacy `course_exam_questions` rows + their `course_exam_question_answers`
            // children cascade via foreign keys when the parent quiz is removed.
            $quiz->delete();
        });
    }

    /* ------------------------------------------------------------------ *
     |  SUBMISSIONS                                                       |
     * ------------------------------------------------------------------ */

    public function paginateSubmissions(
        ?int $quizId,
        ?int $courseId,
        ?int $userId,
        ?array $instructorIds,
        ?array $learnerIds,
        ?array $courseIds,
        ?string $status,
        ?string $search,
        int $perPage = 20,
        ?int $sectionId = null,
        ?string $result = null,
        array $types = [],
        array $quizIds = [],
    ): LengthAwarePaginator {
        $page = $this->submissionsQuery($quizId, $courseId, $userId, $instructorIds, $learnerIds, $courseIds, $status, $search, $sectionId, $result, $types, $quizIds)
            ->withCount(['answers as pending_answers_count' => fn ($a) => $this->pendingAnswer($a)])
            ->with([
                'user:id,name,name_en,name_ar',
                'exam.course:id,title',
                'exam.course.instructors' => CourseInstructors::eager(),
                'exam.cohorts.cohort:id,name',
            ])
            ->latest('id')
            ->paginate($perPage);

        $this->cohorts->attach($page->getCollection(), fn (UserExam $e) => $e->course_id !== null ? (int) $e->course_id : null);

        return $page;
    }

    /**
     * Choices for the Course Details Quizzes filter (Figma 2295:52815: Learner,
     * Instructor, Quiz): only values that occur in this course's submissions,
     * so no choice can return nothing. Bounded like the evaluation options.
     *
     * @return array{learners: list<array{id:int,name:string}>, instructors: list<array{id:int,name:string}>, items: list<array{id:int,name:?string}>}
     */
    /** Learners, instructors and quizzes that have submissions: in one course, or in all when null. */
    public function filterOptions(?int $courseId): array
    {
        $examIds = fn () => $this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('exam_id');

        return [
            'learners'    => $this->people($this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('user_id')),
            // The courses' instructors, who teach and grade (NEW2B-6291).
            'instructors' => CourseInstructors::options(CourseExam::query()->whereIn('id', $examIds())->select('course_id')),
            'items'       => CourseExam::query()->whereIn('id', $examIds())->orderBy('id')->limit(self::OPTIONS_LIMIT)->get(['id', 'title'])
                ->map(fn (CourseExam $e) => ['id' => $e->id, 'name' => $e->title])->values()->all(),
        ];
    }

    private const OPTIONS_LIMIT = 500;

    /** @return list<array{id:int,name:string}> */
    private function people(Builder $ids): array
    {
        return User::query()->whereIn('id', $ids)->orderByRaw(PersonName::userSql('users'))->limit(self::OPTIONS_LIMIT)
            ->get(['id', 'name', 'name_en', 'name_ar'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->getLocalizedName()])->values()->all();
    }

    /** Submissions of one course: the Course Details Quizzes tab count, equal to its list total. */
    public function countForCourse(int $courseId): int
    {
        return $this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->count();
    }

    /**
     * The submissions list query without eager loads or order, shared by the
     * list and its count. `$sectionId` narrows to learners enrolled in that
     * cohort of `$courseId` (Course Details cohort filter).
     */
    private function submissionsQuery(
        ?int $quizId,
        ?int $courseId,
        ?int $userId,
        ?array $instructorIds,
        ?array $learnerIds,
        ?array $courseIds,
        ?string $status,
        ?string $search,
        ?int $sectionId,
        ?string $result = null,
        array $types = [],
        array $quizIds = [],
    ): Builder {
        return UserExam::query()
            // A sent attempt only: an opened rich attempt is a row too (B-162, B-172).
            ->whereRaw(\App\Support\CourseCompletion::submittedExamSql('user_exams'))
            ->whereHas('exam', function ($q) {
                // Any exam that has questions (legacy or rich) — mirrors the
                // relaxed quiz list gate so a legacy quiz's submissions are
                // not hidden from the admin.
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('course_exam_questions')
                        ->whereColumn('course_exam_questions.course_exam_id', 'course_exams.id');
                });
            })
            ->when($quizId, fn ($q) => $q->where('exam_id', $quizId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when(!empty($instructorIds), fn ($q) => $q->whereIn('course_id', CourseInstructors::coursesOf($instructorIds)))
            ->when(!empty($learnerIds), fn ($q) => $q->whereIn('user_id', $learnerIds))
            ->when(!empty($courseIds), fn ($q) => $q->whereIn('course_id', $courseIds))
            // B-134: as B-129 on assignments - an open answer still waiting
            // for a person's score keeps the attempt pending.
            // A legacy exam is scored in user_degree (B-172).
            ->when($status === 'graded', fn ($q) => $q->whereRaw(self::SCORE_SQL.' IS NOT NULL')
                ->whereDoesntHave('answers', fn ($a) => $this->pendingAnswer($a)))
            ->when($status === 'pending', fn ($q) => $q->where(fn ($w) => $w->whereRaw(self::SCORE_SQL.' IS NULL')
                ->orWhereHas('answers', fn ($a) => $this->pendingAnswer($a))))
            // Figma 1983:42584 "Passed / Failed" (D-065): scored attempts only,
            // by QuizVerdict (D-087): the quiz's pass score, else half the
            // full mark - never the stored status (B-172).
            ->when($result === 'passed' || $result === 'failed', fn ($q) => $q
                ->whereRaw(self::SCORE_SQL.' IS NOT NULL')
                ->whereDoesntHave('answers', fn ($a) => $this->pendingAnswer($a))
                ->whereHas('exam', fn ($e) => $e->whereRaw(
                    '(CASE WHEN course_exams.pass_score IS NOT NULL THEN '.self::SCORE_SQL.' >= course_exams.pass_score'
                    .' ELSE ('.self::MAX_SQL.') > 0 AND '.self::SCORE_SQL.' * 2 >= ('.self::MAX_SQL.') END) = ?',
                    [$result === 'passed' ? 1 : 0],
                )))
            ->when($types !== [], fn ($q) => $q->whereHas('exam', fn ($e) => $e->whereIn('type', $types)))
            // The learner's name, the quiz's title or its course's name (NEW2B-6357).
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('user', fn ($inner) => PersonName::whereUserMatches($inner, 'users', $search))
                ->orWhereHas('exam', fn ($item) => \App\Support\AssessmentSearch::itemOrCourse($item, $search))))
            ->when($quizIds !== [], fn ($q) => $q->whereIn('exam_id', $quizIds))
            ->when($courseId && $sectionId, fn ($q) => $this->cohorts->whereInCohort($q, 'user_exams.user_id', $courseId, $sectionId));
    }

    public function showSubmission(int $id): UserExam
    {
        return UserExam::query()
            ->with([
                'user:id,name,name_en,name_ar',
                'exam.course:id,title',
                'exam.course.instructors' => CourseInstructors::eager(),
                'answers.examQuestion',
            ])
            ->findOrFail($id);
    }

    public function gradeAnswer(
        UserExamAnswer $answer,
        int $awardedScore,
        ?string $feedback,
        ?User $reviewer
    ): UserExamAnswer {
        return DB::transaction(function () use ($answer, $awardedScore, $feedback, $reviewer) {
            // `examQuestion` — NOT `question` — see UserExamAnswer::examQuestion()
            // docblock: `question` is shadowed by a legacy same-named column.
            $maxScore = (int) ($answer->examQuestion->score ?? 0);
            $awarded  = max(0, min($awardedScore, $maxScore));

            $answer->update([
                'awarded_score' => $awarded,
                'feedback'      => $feedback,
                'is_correct'    => $maxScore > 0 ? $awarded === $maxScore : null,
            ]);

            $this->recalculateSubmissionTotals($answer->user_exam_id, $reviewer?->id);

            return $answer->fresh(['examQuestion']);
        });
    }

    /* ------------------------------------------------------------------ *
     |  INTERNAL HELPERS                                                  |
     * ------------------------------------------------------------------ */

    /**
     * Save the question list in place.
     *
     * B-133 (High): this deleted every question and re-created the list on
     * each save. `user_exam_answers.question_id` has no foreign key, so the
     * learners' answers survived but pointed at questions that no longer
     * existed - past attempts could not be reviewed and pending open answers
     * could not be graded. Questions sent with their `id` are now updated,
     * new ones created, and only omitted ones removed. An `id` that is not
     * one of THIS quiz's questions is refused.
     */
    private function syncQuestions(CourseExam $quiz, array $questions): void
    {
        $existing = CourseExamQuestion::where('course_exam_id', $quiz->id)->get()->keyBy('id');
        $kept = [];

        foreach (array_values($questions) as $index => $q) {
            $attributes = [
                'position'          => $index,
                'type'              => $q['type'],
                'score'             => (int) ($q['score'] ?? 0),
                'question'          => $q['question_en'], // legacy translatable mirror
                'question_en'       => $q['question_en'],
                'question_ar'       => $q['question_ar'] ?? null,
                'options_en'        => $q['options_en'] ?? null,
                'options_ar'        => $q['options_ar'] ?? null,
                'correct_answer_en' => $q['correct_answer_en'] ?? null,
                'correct_answer_ar' => $q['correct_answer_ar'] ?? null,
                'explanation_en'    => $q['explanation_en'] ?? null,
                'explanation_ar'    => $q['explanation_ar'] ?? null,
            ];

            $id = isset($q['id']) ? (int) $q['id'] : null;
            if ($id !== null) {
                $question = $existing->get($id);
                if ($question === null) {
                    throw ValidationException::withMessages([
                        "questions.{$index}.id" => __('messages.quiz_question_not_in_quiz'),
                    ]);
                }
                $question->update($attributes);
                $kept[] = $id;

                continue;
            }

            CourseExamQuestion::create($attributes + ['course_exam_id' => $quiz->id]);
        }

        $existing->except($kept)->each(fn (CourseExamQuestion $gone) => $gone->delete());
    }

    /**
     * An answer a person still has to score: an open question with no
     * awarded score. Legacy auto-graded answers never carry awarded_score,
     * so the question type decides, not the null alone.
     */
    /**
     * The attempt's score: an attempt of the rich flow (it records a
     * submission_status) keeps it in total_score, null while waiting for a
     * person; a legacy exam row keeps it in user_degree.
     */
    public const SCORE_SQL = '(CASE WHEN user_exams.submission_status IS NOT NULL THEN user_exams.total_score ELSE user_exams.user_degree END)';

    /** The attempt's full mark, as the learner profile reads it. */
    public const MAX_SQL = 'COALESCE(NULLIF(user_exams.max_score, 0), NULLIF(course_exams.total_score, 0), course_exams.degree, 0)';

    private function pendingAnswer($answers)
    {
        return $answers->whereNull('awarded_score')
            // `examQuestion`: a `question` string column shadows `question()`.
            ->whereHas('examQuestion', fn ($q) => $q->where('type', 'open'));
    }

    private function syncCohorts(CourseExam $quiz, string $scope, array $cohortIds): void
    {
        CourseExamCohort::where('course_exam_id', $quiz->id)->delete();

        if ($scope !== 'specific') {
            return;
        }

        foreach (array_unique($cohortIds) as $cohortId) {
            CourseExamCohort::create([
                'course_exam_id'    => $quiz->id,
                'course_section_id' => $cohortId,
            ]);
        }
    }

    private function sumQuestionScores(array $questions): int
    {
        return array_reduce($questions, fn ($carry, $q) => $carry + (int) ($q['score'] ?? 0), 0);
    }

    private function recalculateSubmissionTotals(int $submissionId, ?int $reviewerId): void
    {
        $submission = UserExam::with('answers')->find($submissionId);
        if (!$submission) {
            return;
        }

        $total = $submission->answers->sum(fn ($a) => (int) ($a->awarded_score ?? 0));

        $update = [
            'total_score' => $total,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
        ];

        // A submitted attempt takes its new score and verdict (D-087): grading
        // an open question used to leave `user_degree` and `status` as they were
        // at submit time, so the Dashboard and the certificate judged a stale
        // score (NEW2B-6232).
        $wasPassed = $submission->status === 'success';
        if ($submission->submission_status === UserExam::SUBMISSION_SUBMITTED) {
            $exam = $submission->exam()->first(['id', 'pass_score', 'total_score']);
            $max  = (float) ($exam?->total_score ?: $submission->max_score);
            $update['user_degree'] = $total;
            $update['status'] = QuizVerdict::status(
                (float) $total,
                $exam?->pass_score !== null ? (float) $exam->pass_score : null,
                $max,
            );
        }

        $submission->update($update);

        if (! $wasPassed && ($update['status'] ?? null) === 'success') {
            app(CertificateService::class)->issueFromExam($submission->fresh(['exam', 'course']));
        }
    }
}
