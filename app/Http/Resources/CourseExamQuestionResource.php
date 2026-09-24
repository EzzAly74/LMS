<?php

namespace App\Http\Resources;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseExamQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'question' => $this->getTranslation('question', app()->getLocale()),
            'answers'  => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($a) => [
                'id'     => $a->id,
                'answer' => $a->getTranslation('answer', app()->getLocale()),
            ] + $this->correctnessFor($request, $a))),
        ];
    }

    /**
     * Expose `is_correct` to admins only.
     *
     * B-08 (High): this resource emitted `is_correct` for every answer, and
     * `GET /api/v1/courses/{course}/exams/{exam}` sits behind `auth.user`
     * alone — no role check and no enrolment check. Any authenticated learner
     * could read the correct answers to any exam of any course before sitting
     * it. Together with B-09 (client-driven grading) the assessment system had
     * no integrity at all.
     *
     * The admin UI legitimately needs the flag to render and edit the answer
     * key, so it is gated on the principal being an Admin rather than removed.
     *
     * @return array{is_correct?: bool}
     */
    private function correctnessFor(Request $request, mixed $answer): array
    {
        return $request->user() instanceof Admin
            ? ['is_correct' => (bool) $answer->is_correct]
            : [];
    }
}
