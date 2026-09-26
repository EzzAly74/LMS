<?php

namespace App\Services\Admin;

use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Support\LocalizedJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The evaluation template builder (Figma 2409:132793 / 2409:133222; D-054).
 *
 * A template is written with its questions in one transaction: a half-saved
 * template would be put to learners with some questions missing.
 *
 * Read-only once answered (decided by the human 2026-09-26): an update is
 * refused if any learner has responded. The check runs under a row lock on the
 * template, so a response recorded while an edit is in flight cannot slip
 * between the check and the write (the learner submit takes the same lock).
 */
class EvaluationTemplateService
{
    public function __construct(private readonly AdminEvaluationReportService $reports) {}

    /**
     * @param  array{name: array{en:string, ar:string}, course_id: ?int, section_id: ?int, questions: list<array{title: array{en:string, ar:string}, type:string, required:bool, scale_label_min?: ?array, scale_label_max?: ?array}>}  $data
     */
    public function create(array $data): EvaluationCategory
    {
        return DB::transaction(function () use ($data) {
            $template = EvaluationCategory::query()->create([
                'name'       => $data['name'],
                'course_id'  => $data['course_id'],
                'section_id' => $data['section_id'],
            ]);
            $this->writeQuestions($template, $data['questions']);

            return $template;
        });
    }

    /** @param  array  $data  as for create() */
    public function update(EvaluationCategory $template, array $data): EvaluationCategory
    {
        return DB::transaction(function () use ($template, $data) {
            $locked = EvaluationCategory::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasResponses()) {
                throw ValidationException::withMessages(['template' => __('messages.evaluation_template_locked')]);
            }

            $locked->update([
                'name'       => $data['name'],
                'course_id'  => $data['course_id'],
                'section_id' => $data['section_id'],
            ]);
            // Nobody has answered, so no answer refers to these question ids:
            // replacing them is safe and keeps the order the admin gave.
            $locked->evaluations()->delete();
            $this->writeQuestions($locked, $data['questions']);

            return $locked;
        });
    }

    /** The template as the builder edits it, plus what the summary card shows. */
    public function show(EvaluationCategory $template): array
    {
        $template->load(['evaluations' => fn ($q) => $q->orderBy('id'), 'course:id,title', 'section:id,name,course_id']);

        $highest = DB::query()
            ->fromSub(
                DB::table('user_course_evaluations as uce')
                    ->where('uce.evaluation_category_id', $template->id)
                    ->groupBy('uce.user_id', 'uce.course_id')
                    ->select(DB::raw($this->reports->scoreExpression('uce').' as score')),
                's',
            )
            ->max('score');

        return [
            'id'        => $template->id,
            'name'      => ['en' => $template->getTranslation('name', 'en', false), 'ar' => $template->getTranslation('name', 'ar', false)],
            'course'    => $template->course ? ['id' => $template->course->id, 'name' => $template->course->title] : null,
            'cohort'    => $template->section ? ['id' => $template->section->id, 'name' => $template->section->name] : null,
            'locked'    => $template->hasResponses(),
            'highest_score' => $highest !== null ? (float) $highest : null,
            'score_max' => $this->reports->scoreMax(),
            'questions' => $template->evaluations->map(fn (Evaluation $e) => [
                'id'       => $e->id,
                'title'    => $this->pair($e, 'title'),
                'type'     => $e->type,
                'required' => (bool) $e->is_required,
                'scale_label_min' => $e->type === 'scale' ? $this->pair($e, 'scale_label_min') : null,
                'scale_label_max' => $e->type === 'scale' ? $this->pair($e, 'scale_label_max') : null,
            ])->values()->all(),
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }

    /** Bound on the course list; far above the number of evaluable courses. */
    private const MAX_OPTION_COURSES = 500;

    /** @return array{courses: list<array{id:int, name:?string, cohorts: list<array{id:int, name:?string}>}>, pass_threshold: float, score_max: int} */
    public function builderOptions(): array
    {
        $courses = \App\Models\Course::query()
            ->where('is_evaluate', 1)
            ->with(['sections' => fn ($q) => $q->select('id', 'course_id', 'name')->orderBy('id')])
            ->orderBy('id')
            ->limit(self::MAX_OPTION_COURSES)
            ->get(['id', 'title']);

        return [
            'courses' => $courses->map(fn ($c) => [
                'id'      => $c->id,
                'name'    => $c->title,
                'cohorts' => $c->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            ])->sortBy(fn ($c) => mb_strtolower((string) $c['name']))->values()->all(),
            'pass_threshold' => $this->reports->passThreshold(),
            'score_max'      => $this->reports->scoreMax(),
        ];
    }

    private function writeQuestions(EvaluationCategory $template, array $questions): void
    {
        foreach ($questions as $q) {
            $isScale = $q['type'] === 'scale';
            $template->evaluations()->create([
                'type'            => $q['type'],
                'title'           => $q['title'],
                'is_required'     => (bool) $q['required'],
                'scale_label_min' => $isScale ? $q['scale_label_min'] : null,
                'scale_label_max' => $isScale ? $q['scale_label_max'] : null,
            ]);
        }
    }

    /** Both languages of a translatable attribute, without locale fallback. */
    private function pair(Evaluation $e, string $attribute): array
    {
        $raw = $e->getAttributes()[$attribute] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            // A legacy plain-string value: show it in both fields to be corrected.
            $plain = LocalizedJson::pick($raw);

            return ['en' => $plain, 'ar' => $plain];
        }

        return ['en' => $decoded['en'] ?? null, 'ar' => $decoded['ar'] ?? null];
    }
}
