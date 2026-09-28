<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminAnswerGradeRequest;
use App\Http\Requests\Api\Admin\AdminAssignmentStoreRequest;
use App\Http\Requests\Api\Admin\AdminAssignmentUpdateRequest;
use App\Http\Resources\Admin\AdminAssignmentListResource;
use App\Http\Resources\Admin\AdminAssignmentResource;
use App\Http\Resources\Admin\AdminAssignmentSubmissionDetailResource;
use App\Http\Resources\Admin\AdminAssignmentSubmissionResource;
use App\Http\Resources\Admin\AssignmentAttachmentPayload;
use App\Http\Requests\Api\Admin\AdminAssignmentAttachmentRequest;
use App\Models\CourseAssignmentQuestion;
use App\Services\Assignments\AssignmentFileService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Http\Traits\SubmissionListParams;
use App\Models\CourseAssignment;
use App\Models\CourseSession;
use App\Models\User;
use App\Models\UserCourseAssignment;
use App\Models\UserCourseAssignmentAnswer;
use App\Services\Admin\AdminAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoints for the rich-question assignment workflow defined by the
 * 2026 Figma redesign. This controller is intentionally NEW and additive —
 * the legacy file-upload controller (CourseAssignmentController) remains
 * untouched for backward compatibility with the learner-facing API.
 */
class AdminAssignmentController extends ApiController
{
    use SubmissionListParams;

    public function __construct(private readonly AdminAssignmentService $service) {}

    /* ------------------------------------------------------------------ *
     | Assignments                                                        |
     * ------------------------------------------------------------------ */

    public function index(Request $request): JsonResponse
    {
        $assignments = $this->service->paginate(
            $request->integer('course_id') ?: null,
            $request->get('search'),
            $request->get('status'),
            (int) $request->get('per_page', 20),
        );

        return $this->paginated(
            __('messages.retrieved'),
            AdminAssignmentListResource::collection($assignments),
        );
    }

    public function summary(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->summary());
    }

    public function listMinimal(Request $request): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            $this->service->listMinimal($request->get('search'), (int) $request->get('limit', 200)),
        );
    }

    public function cohorts(Request $request): JsonResponse
    {
        $courseId = $request->integer('course_id');
        $cohorts = CourseSession::query()
            ->select(['id', 'course_id', 'title'])
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('title')
            ->get();

        return $this->success(__('messages.retrieved'), $cohorts);
    }

    public function instructors(): JsonResponse
    {
        $instructors = User::query()
            ->select(['id', 'name'])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Admin', 'Instructor']))
            ->orderBy('name')
            ->limit(500)
            ->get();

        return $this->success(__('messages.retrieved'), $instructors);
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            new AdminAssignmentResource($this->service->show($id)),
        );
    }

    public function store(AdminAssignmentStoreRequest $request): JsonResponse
    {
        /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
        $user = $request->user();

        $assignment = $this->service->create($request->validated(), $user);

        return $this->created(__('messages.created'), new AdminAssignmentResource($assignment));
    }

    public function update(int $id, AdminAssignmentUpdateRequest $request): JsonResponse
    {
        $assignment = CourseAssignment::findOrFail($id);
        $updated    = $this->service->update($assignment, $request->validated());

        return $this->success(__('messages.updated'), new AdminAssignmentResource($updated));
    }

    public function destroy(int $id): JsonResponse
    {
        $assignment = CourseAssignment::findOrFail($id);
        $this->service->delete($assignment);

        return $this->deleted();
    }

    /* ------------------------------------------------------------------ *
     | Submissions                                                        |
     * ------------------------------------------------------------------ */

    public function submissions(Request $request): JsonResponse
    {
        $instructors = $request->input('instructor_ids');
        $learners    = $request->input('learner_ids');
        $courses     = $request->input('course_ids');

        $this->validateCohortFilter($request);

        $submissions = $this->service->paginateSubmissions(
            $request->integer('assignment_id') ?: null,
            $request->integer('course_id') ?: null,
            $request->integer('user_id') ?: null,
            is_array($instructors) ? array_map('intval', $instructors) : null,
            is_array($learners)    ? array_map('intval', $learners)    : null,
            is_array($courses)     ? array_map('intval', $courses)     : null,
            $request->get('status'),
            $request->get('search'),
            $this->submissionsPerPage($request),
            $request->integer('section_id') ?: null,
        );

        return $this->paginated(
            __('messages.retrieved'),
            AdminAssignmentSubmissionResource::collection($submissions),
        );
    }

    /** GET admin/assignments/submissions/filter-options?course_id= - Course Details filter (2294:51575). */
    public function submissionFilterOptions(Request $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->filterOptions($this->validatedCourseId($request)));
    }

    public function showSubmission(int $id): JsonResponse
    {
        $submission = $this->service->showSubmission($id);

        return $this->success(
            __('messages.retrieved'),
            new AdminAssignmentSubmissionDetailResource($submission),
        );
    }

    public function gradeAnswer(int $submissionId, int $answerId, AdminAnswerGradeRequest $request): JsonResponse
    {
        $answer = UserCourseAssignmentAnswer::with('question')
            ->where('user_course_assignment_id', $submissionId)
            ->where('id', $answerId)
            ->firstOrFail();

        $user = $request->user();

        $graded = $this->service->gradeAnswer(
            $answer,
            (int) $request->input('awarded_score'),
            $request->input('feedback'),
            $user,
        );

        $submission = $this->service->showSubmission($submissionId);

        return $this->success(
            __('messages.updated'),
            [
                'answer'     => [
                    'id'             => $graded->id,
                    'awarded_score'  => (int) $graded->awarded_score,
                    'is_correct'     => $graded->is_correct,
                    'feedback'       => $graded->feedback,
                ],
                'submission' => new AdminAssignmentSubmissionDetailResource($submission),
            ],
        );
    }

    /* ------------------------------------------------------------------ *
     |  FILE QUESTIONS (D-033, D-064)                                     |
     * ------------------------------------------------------------------ */

    /** GET admin/assignments/submissions/{submission}/answers/{answer}/file */
    public function answerFile(int $submissionId, int $answerId, AssignmentFileService $files): StreamedResponse
    {
        $answer = UserCourseAssignmentAnswer::query()
            ->where('user_course_assignment_id', $submissionId)
            ->whereKey($answerId)
            ->whereNotNull('file_path')
            ->firstOrFail();

        return $files->download($answer->file_path, (string) $answer->file_name);
    }

    /** GET admin/assignments/{assignment}/questions/{question}/attachment */
    public function questionAttachment(int $assignmentId, int $questionId, AssignmentFileService $files): StreamedResponse
    {
        $question = $this->fileQuestion($assignmentId, $questionId);
        abort_if($question->attachment_path === null, 404);

        return $files->download($question->attachment_path, (string) $question->attachment_name);
    }

    /**
     * POST admin/assignments/{assignment}/questions/{question}/attachment
     *
     * Uploaded after the assignment is saved (the question needs an id), one
     * file per question; a new upload replaces the old one.
     */
    public function uploadQuestionAttachment(int $assignmentId, int $questionId, AdminAssignmentAttachmentRequest $request, AssignmentFileService $files): JsonResponse
    {
        $question = $files->storeQuestionAttachment($this->fileQuestion($assignmentId, $questionId), $request->file('file'));

        return $this->success(__('messages.updated'), AssignmentAttachmentPayload::for($question));
    }

    /** DELETE admin/assignments/{assignment}/questions/{question}/attachment */
    public function removeQuestionAttachment(int $assignmentId, int $questionId, AssignmentFileService $files): JsonResponse
    {
        $files->removeQuestionAttachment($this->fileQuestion($assignmentId, $questionId));

        return $this->success(__('messages.deleted'), null);
    }

    /** A file question of this assignment, or 404 / 422. */
    private function fileQuestion(int $assignmentId, int $questionId): CourseAssignmentQuestion
    {
        $question = CourseAssignmentQuestion::query()
            ->where('course_assignment_id', $assignmentId)
            ->whereKey($questionId)
            ->firstOrFail();

        abort_unless($question->isFile(), 422, __('messages.assignment_question_not_file'));

        return $question;
    }
}
