<?php

namespace App\Services\Admin;

use App\Models\CourseExam;
use App\Models\CourseExamCohort;
use App\Models\CourseExamQuestion;
use App\Models\User;
use App\Models\UserExam;
use App\Models\UserExamAnswer;
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
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                      ->orWhere('title_ar', 'like', "%{$search}%");
            }))
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
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                      ->orWhere('title_ar', 'like', "%{$search}%");
            }))
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
                'creator:id,name',
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

            return $this->show($quiz->id);
        });
    }

    public function update(CourseExam $quiz, array $data): CourseExam
    {
        return DB::transaction(function () use ($quiz, $data) {
            $total = $this->sumQuestionScores($data['questions'] ?? []);

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

            return $this->show($quiz->id);
        });
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
    ): LengthAwarePaginator {
        $page = $this->submissionsQuery($quizId, $courseId, $userId, $instructorIds, $learnerIds, $courseIds, $status, $search, $sectionId, $result, $types)
            ->withCount(['answers as pending_answers_count' => fn ($a) => $this->pendingAnswer($a)])
            ->with([
                'user:id,name',
                'exam.course:id,title',
                'exam.creator:id,name',
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
    public function filterOptions(int $courseId): array
    {
        $examIds = fn () => $this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('exam_id');

        return [
            'learners'    => $this->people($this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('user_id')),
            'instructors' => $this->people(CourseExam::query()->whereIn('id', $examIds())->whereNotNull('created_by')->select('created_by')),
            'items'       => CourseExam::query()->whereIn('id', $examIds())->orderBy('id')->limit(self::OPTIONS_LIMIT)->get(['id', 'title'])
                ->map(fn (CourseExam $e) => ['id' => $e->id, 'name' => $e->title])->values()->all(),
        ];
    }

    private const OPTIONS_LIMIT = 500;

    /** @return list<array{id:int,name:string}> */
    private function people(Builder $ids): array
    {
        return User::query()->whereIn('id', $ids)->orderBy('name')->limit(self::OPTIONS_LIMIT)
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
    ): Builder {
        return UserExam::query()
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
            ->when(!empty($instructorIds), fn ($q) => $q->whereHas('exam', fn ($inner) => $inner->whereIn('created_by', $instructorIds)))
            ->when(!empty($learnerIds), fn ($q) => $q->whereIn('user_id', $learnerIds))
            ->when(!empty($courseIds), fn ($q) => $q->whereIn('course_id', $courseIds))
            // B-134: as B-129 on assignments - an open answer still waiting
            // for a person's score keeps the attempt pending.
            ->when($status === 'graded', fn ($q) => $q->whereNotNull('total_score')
                ->whereDoesntHave('answers', fn ($a) => $this->pendingAnswer($a)))
            ->when($status === 'pending', fn ($q) => $q->where(fn ($w) => $w->whereNull('total_score')
                ->orWhereHas('answers', fn ($a) => $this->pendingAnswer($a))))
            // Figma 1983:42584 "Passed / Failed" (D-065): scored attempts only,
            // against the quiz's own pass score, else the legacy pass status.
            ->when($result === 'passed' || $result === 'failed', fn ($q) => $q
                ->whereNotNull('total_score')
                ->whereDoesntHave('answers', fn ($a) => $this->pendingAnswer($a))
                ->whereHas('exam', fn ($e) => $e->whereRaw(
                    '(CASE WHEN course_exams.pass_score IS NULL THEN LOWER(COALESCE(user_exams.status, \'\')) IN (\'passed\', \'completed\', \'success\') ELSE user_exams.total_score >= course_exams.pass_score END) = ?',
                    [$result === 'passed' ? 1 : 0],
                )))
            ->when($types !== [], fn ($q) => $q->whereHas('exam', fn ($e) => $e->whereIn('type', $types)))
            ->when($search, fn ($q) => $q->whereHas('user', fn ($inner) => $inner->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($courseId && $sectionId, fn ($q) => $this->cohorts->whereInCohort($q, 'user_exams.user_id', $courseId, $sectionId));
    }

    public function showSubmission(int $id): UserExam
    {
        return UserExam::query()
            ->with([
                'user:id,name',
                'exam.course:id,title',
                'exam.creator:id,name',
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

        $submission->update([
            'total_score' => $total,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
        ]);
    }
}
