<?php

namespace App\Services\Admin;

use App\Models\CourseAssignment;
use App\Models\CourseAssignmentCohort;
use App\Models\CourseAssignmentQuestion;
use App\Models\User;
use App\Models\UserCourseAssignment;
use App\Models\UserCourseAssignmentAnswer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Assignments\AssignmentFileService;

/**
 * Service backing the rich (question-based) admin assignment workflow.
 *
 * Note: this is a NEW service class. The legacy file-upload service
 * (App\Services\CourseAssignmentService) is left untouched.
 */
class AdminAssignmentService
{
    public function __construct(
        private readonly LearnerCohorts $cohorts,
        private readonly AssignmentFileService $attachments,
    ) {}

    /* ------------------------------------------------------------------ *
     |  ASSIGNMENT CRUD                                                   |
     * ------------------------------------------------------------------ */

    public function paginate(
        ?int $courseId,
        ?string $search,
        ?string $status,
        int $perPage = 20
    ): LengthAwarePaginator {
        return CourseAssignment::query()
            ->with(['course:id,title', 'cohorts.session:id,title'])
            ->withCount('questions')
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                      ->orWhere('title_ar', 'like', "%{$search}%");
            }))
            // NB: no `course_assignment_questions` existence gate. Legacy
            // assignments are file-based (a `file` upload, zero question
            // rows); requiring a question row hid every existing assignment
            // from the admin dashboard.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function summary(): array
    {
        $assignments = CourseAssignment::query();

        return [
            'assignments_count' => (clone $assignments)->count(),
            'courses_count'     => (clone $assignments)->distinct('course_id')->count('course_id'),
        ];
    }

    public function listMinimal(?string $search, int $limit = 200): array
    {
        return CourseAssignment::query()
            ->select(['id', 'title', 'title_ar', 'course_id', 'status'])
            ->with('course:id,title')
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                      ->orWhere('title_ar', 'like', "%{$search}%");
            }))
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn ($a) => [
                'id'           => $a->id,
                'title'        => $a->title,
                'title_ar'     => $a->title_ar,
                'course_id'    => $a->course_id,
                'course_title' => optional($a->course)->title,
                'status'       => $a->status,
            ])
            ->all();
    }

    public function show(int $id): CourseAssignment
    {
        return CourseAssignment::query()
            ->with([
                'course:id,title',
                'creator:id,name',
                'questions',
                'cohorts.session:id,title',
            ])
            ->findOrFail($id);
    }

    public function create(array $data, ?Authenticatable $creator): CourseAssignment
    {
        return DB::transaction(function () use ($data, $creator) {
            $assignment = CourseAssignment::query()->create([
                'course_id'       => $data['course_id'],
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
            ]);

            $this->syncQuestions($assignment, $data['questions'] ?? []);
            $this->syncCohorts($assignment, $data['cohort_scope'], $data['cohort_ids'] ?? []);

            return $this->show($assignment->id);
        });
    }

    public function update(CourseAssignment $assignment, array $data): CourseAssignment
    {
        return DB::transaction(function () use ($assignment, $data) {
            $assignment->update([
                'course_id'       => $data['course_id'],
                'title'           => $data['title'],
                'title_ar'        => $data['title_ar'] ?? null,
                'instructions_en' => $data['instructions_en'] ?? null,
                'instructions_ar' => $data['instructions_ar'] ?? null,
                'due_date'        => $data['due_date'] ?? null,
                'cohort_scope'    => $data['cohort_scope'],
                'pass_score'      => $data['pass_score'] ?? null,
                'status'          => $data['status'] ?? $assignment->status,
                'type'            => $data['type'] ?? null,
                'total_score'     => $this->sumQuestionScores($data['questions'] ?? []),
            ]);

            $this->syncQuestions($assignment, $data['questions'] ?? []);
            $this->syncCohorts($assignment, $data['cohort_scope'], $data['cohort_ids'] ?? []);

            return $this->show($assignment->id);
        });
    }

    public function delete(CourseAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            CourseAssignmentQuestion::where('course_assignment_id', $assignment->id)->get()
                ->each(function (CourseAssignmentQuestion $q) {
                    $this->attachments->removeQuestionAttachment($q);
                    $this->attachments->removeAnswerFiles($q);
                });
            CourseAssignmentQuestion::where('course_assignment_id', $assignment->id)->delete();
            CourseAssignmentCohort::where('course_assignment_id', $assignment->id)->delete();
            $assignment->delete();
        });
    }

    /* ------------------------------------------------------------------ *
     |  SUBMISSIONS                                                       |
     * ------------------------------------------------------------------ */

    public function paginateSubmissions(
        ?int $assignmentId,
        ?int $courseId,
        ?int $userId,
        ?array $instructorIds,
        ?array $learnerIds,
        ?array $courseIds,
        ?string $status,
        ?string $search,
        int $perPage = 20,
        ?int $sectionId = null,
    ): LengthAwarePaginator {
        $page = $this->submissionsQuery($assignmentId, $courseId, $userId, $instructorIds, $learnerIds, $courseIds, $status, $search, $sectionId)
            ->with([
                'user:id,name,machine_code,department_name',
                'assignment.course:id,title',
                'assignment.creator:id,name',
                'assignment.cohorts.session:id,title',
            ])
            ->withCount(['answers as pending_answers_count' => fn ($a) => $a->whereNull('awarded_score')])
            ->latest('id')
            ->paginate($perPage);

        $this->cohorts->attach(
            $page->getCollection(),
            fn (UserCourseAssignment $s) => $s->assignment?->course_id !== null ? (int) $s->assignment->course_id : null,
        );

        return $page;
    }

    /**
     * Choices for the Course Details Assignments filter (Figma 2294:51575:
     * Learner, Instructor, Assignment): only values that occur in this
     * course's submissions, so no choice can return nothing.
     *
     * @return array{learners: list<array{id:int,name:string}>, instructors: list<array{id:int,name:string}>, items: list<array{id:int,name:?string}>}
     */
    public function filterOptions(int $courseId): array
    {
        $assignmentIds = fn () => $this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('course_assignment_id');
        $ar = app()->getLocale() === 'ar';

        return [
            'learners'    => $this->people($this->submissionsQuery(null, $courseId, null, null, null, null, null, null, null)->select('user_id')),
            'instructors' => $this->people(CourseAssignment::query()->whereIn('id', $assignmentIds())->whereNotNull('created_by')->select('created_by')),
            'items'       => CourseAssignment::query()->whereIn('id', $assignmentIds())->orderBy('id')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'title', 'title_ar'])
                ->map(fn (CourseAssignment $a) => ['id' => $a->id, 'name' => ($ar ? ($a->title_ar ?: $a->title) : ($a->title ?: $a->title_ar))])
                ->values()->all(),
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

    /** Submissions of one course: the Course Details Assignments tab count, equal to its list total. */
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
        ?int $assignmentId,
        ?int $courseId,
        ?int $userId,
        ?array $instructorIds,
        ?array $learnerIds,
        ?array $courseIds,
        ?string $status,
        ?string $search,
        ?int $sectionId,
    ): Builder {
        return UserCourseAssignment::query()
            ->when($assignmentId, fn ($q) => $q->where('course_assignment_id', $assignmentId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($courseId, fn ($q) => $q->whereHas('assignment', fn ($inner) => $inner->where('course_id', $courseId)))
            ->when(!empty($instructorIds), fn ($q) => $q->whereHas('assignment', fn ($inner) => $inner->whereIn('created_by', $instructorIds)))
            ->when(!empty($learnerIds), fn ($q) => $q->whereIn('user_id', $learnerIds))
            ->when(!empty($courseIds), fn ($q) => $q->whereHas('assignment', fn ($inner) => $inner->whereIn('course_id', $courseIds)))
            // B-129: the learner flow writes a running total from the first
            // answer, so `total_score` alone called every submission graded.
            // Graded = scored and no answer still waiting for a person.
            ->when($status === 'graded', fn ($q) => $q->whereNotNull('total_score')
                ->whereDoesntHave('answers', fn ($a) => $a->whereNull('awarded_score')))
            ->when($status === 'pending', fn ($q) => $q->where(fn ($w) => $w->whereNull('total_score')
                ->orWhereHas('answers', fn ($a) => $a->whereNull('awarded_score'))))
            ->when($search, fn ($q) => $q->whereHas('user', fn ($inner) => $inner->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($courseId && $sectionId, fn ($q) => $this->cohorts->whereInCohort($q, 'user_course_assignments.user_id', $courseId, $sectionId));
    }

    public function showSubmission(int $id): UserCourseAssignment
    {
        return UserCourseAssignment::query()
            ->with([
                'user:id,name,machine_code,department_name',
                'assignment.course:id,title',
                'assignment.course:id,title,course_type,category_id',
                'assignment.course.category:id,name',
                'assignment.creator:id,name',
                'answers.question',
            ])
            ->findOrFail($id);
    }

    public function gradeAnswer(
        UserCourseAssignmentAnswer $answer,
        int $awardedScore,
        ?string $feedback,
        // B-131: typed ?User, but the only route here is role:Admin, so every
        // grade was a TypeError (500). The grader is the signed-in admin.
        ?Authenticatable $reviewer
    ): UserCourseAssignmentAnswer {
        return DB::transaction(function () use ($answer, $awardedScore, $feedback, $reviewer) {
            $maxScore = (int) ($answer->question->score ?? 0);
            $awarded  = max(0, min($awardedScore, $maxScore));

            $answer->update([
                'awarded_score' => $awarded,
                'feedback'      => $feedback,
                'is_correct'    => $maxScore > 0 ? $awarded === $maxScore : null,
            ]);

            $this->recalculateSubmissionTotals($answer->user_course_assignment_id, $reviewer ? (int) $reviewer->getAuthIdentifier() : null);

            return $answer->fresh(['question']);
        });
    }

    /* ------------------------------------------------------------------ *
     |  INTERNAL HELPERS                                                  |
     * ------------------------------------------------------------------ */

    /**
     * Save the question list in place.
     *
     * B-128 (High): this deleted every question and re-created the list on
     * each save. `user_course_assignment_answers` cascades on its question,
     * so editing an assignment - even a typo in its title - erased every
     * learner's answers and grades. Questions sent with their `id` are now
     * updated (answers kept); new ones are created; only questions left out
     * are removed, with their answers and attachment.
     *
     * An `id` that is not one of THIS assignment's questions is refused
     * rather than silently created: it would otherwise let one assignment's
     * payload move another assignment's question.
     */
    private function syncQuestions(CourseAssignment $assignment, array $questions): void
    {
        $existing = CourseAssignmentQuestion::where('course_assignment_id', $assignment->id)->get()->keyBy('id');
        $kept = [];

        foreach (array_values($questions) as $index => $q) {
            $attributes = [
                'position'          => $index,
                'type'              => $q['type'],
                'score'             => (int) ($q['score'] ?? 0),
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
                        "questions.{$index}.id" => __('messages.assignment_question_not_in_assignment'),
                    ]);
                }
                // A question that stops being a file question drops its attachment.
                if ($question->isFile() && $q['type'] !== CourseAssignmentQuestion::TYPE_FILE) {
                    $this->attachments->removeQuestionAttachment($question);
                }
                $question->update($attributes);
                $kept[] = $id;

                continue;
            }

            CourseAssignmentQuestion::create($attributes + ['course_assignment_id' => $assignment->id]);
        }

        $existing->except($kept)->each(function (CourseAssignmentQuestion $gone) {
            $this->attachments->removeQuestionAttachment($gone);
            $this->attachments->removeAnswerFiles($gone);
            $gone->delete();
        });
    }

    private function syncCohorts(CourseAssignment $assignment, string $scope, array $cohortIds): void
    {
        CourseAssignmentCohort::where('course_assignment_id', $assignment->id)->delete();

        if ($scope !== 'specific') {
            return;
        }

        foreach (array_unique($cohortIds) as $sessionId) {
            CourseAssignmentCohort::create([
                'course_assignment_id' => $assignment->id,
                'course_session_id'    => $sessionId,
            ]);
        }
    }

    private function sumQuestionScores(array $questions): int
    {
        return array_reduce($questions, fn ($carry, $q) => $carry + (int) ($q['score'] ?? 0), 0);
    }

    private function recalculateSubmissionTotals(int $submissionId, ?int $reviewerId): void
    {
        $submission = UserCourseAssignment::with('answers')->find($submissionId);
        if (!$submission) {
            return;
        }

        $total = $submission->answers->sum(function ($a) {
            return (int) ($a->awarded_score ?? 0);
        });

        $submission->update([
            'total_score' => $total,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
        ]);
    }
}
