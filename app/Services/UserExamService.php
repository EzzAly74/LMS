<?php

namespace App\Services;

use App\Events\QuizSubmitted;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseExamQuestionAnswer;
use App\Models\User;
use App\Models\UserExam;
use Illuminate\Database\Eloquent\Collection;

class UserExamService
{
    public function __construct(
        private readonly CertificateService $certificates,
    ) {}

    public function hasAlreadySubmitted(int $userId, int $examId): bool
    {
        return UserExam::where('user_id', $userId)->where('exam_id', $examId)->exists();
    }

    /**
     * Submit exam answers, auto-grade, and return the stored UserExam.
     *
     * Expected $questions format:
     * [
     *   ['question_id' => 1, 'question_title' => '...', 'answer_id' => 3],
     *   ...
     * ]
     */
    public function submit(User $user, Course $course, CourseExam $exam, array $questions): UserExam
    {
        // B-09 (High): grading used to be driven entirely by the client's
        // payload. The score was
        //     ($exam->degree / count($questions)) * $correctAnswers
        // where $questions came straight from the request, so submitting a
        // single question you knew the answer to scored 100%. `question_id`
        // was never checked against this exam, `answer_id` had no `exists`
        // rule so any answer row in the database was accepted, and
        // `question_title` was stored verbatim from the client.
        //
        // Grading is now driven by the exam's own questions, loaded from the
        // database. The client payload is only consulted to look up which
        // answer the learner picked for each of *this exam's* questions.

        /** @var \Illuminate\Database\Eloquent\Collection $examQuestions */
        $examQuestions = $exam->questions()->with('answers')->get();

        // answer_id chosen by the learner, keyed by question_id. Anything the
        // client sent for a question that is not part of this exam is ignored.
        $chosen = [];
        foreach ($questions as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid > 0) {
                $chosen[$qid] = (int) ($row['answer_id'] ?? 0);
            }
        }

        $correctAnswers = 0;
        $rows           = [];

        foreach ($examQuestions as $question) {
            $answerId = $chosen[$question->id] ?? null;

            // The chosen answer must belong to this question. An answer id
            // from another question — or another exam entirely — resolves to
            // null and is graded as unanswered.
            $answer = $answerId
                ? $question->answers->firstWhere('id', $answerId)
                : null;

            $isCorrect = (bool) ($answer->is_correct ?? false);

            $rows[] = [
                'question_id' => $question->id,
                // Question text is read from the database, never echoed back
                // from the request.
                'question'    => $question->question,
                'answer_id'   => $answer->id ?? null,
                'answer'      => $answer->answer ?? null,
                'is_correct'  => $isCorrect,
            ];

            if ($isCorrect) {
                $correctAnswers++;
            }
        }

        $userExam = UserExam::create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'exam_id'   => $exam->id,
        ]);

        foreach ($rows as $row) {
            $userExam->answers()->create($row);
        }

        // Denominator is the exam's real question count, not the client's.
        $totalQuestions = $examQuestions->count();
        $userDegree     = $totalQuestions > 0
            ? ($exam->degree / $totalQuestions) * $correctAnswers
            : 0;

        $status = $userDegree >= ($exam->degree / 2) ? 'success' : 'fail';

        $userExam->update([
            'user_degree' => $userDegree,
            'status'      => $status,
        ]);

        // Issue the first-class certificate the moment a final exam is
        // passed on a certificate-bearing course. Idempotent + eligibility
        // gated inside CertificateService.
        if ($status === 'success') {
            $this->certificates->issueFromExam($userExam);
        }

        event(new QuizSubmitted($userExam));

        return $userExam->load(['exam:id,title,degree,is_final', 'course:id,title,certificate']);
    }

    public function getUserExams(int $userId): Collection
    {
        return UserExam::where('user_id', $userId)
            ->with(['course:id,title,certificate', 'exam:id,title,degree,is_final'])
            ->latest()
            ->get();
    }

    public function getUserExam(int $userId, int $examId): ?UserExam
    {
        return UserExam::where('user_id', $userId)
            ->where('id', $examId)
            ->with(['course:id,title,certificate', 'exam:id,title,degree,is_final', 'answers'])
            ->first();
    }
}
