<?php

namespace App\Http\Controllers\apis\Admin;

use App\AiAssessments\AssessmentAccess;
use App\AiAssessments\Export\AssessmentExporter;
use App\AiAssessments\GenerationRefused;
use App\AiAssessments\GenerationService;
use App\AiAssessments\SourceService;
use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentDuplicateRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentExportRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentIndexRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentQuestionStatusRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentQuestionUpdateRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentReorderRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentSourceTextRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentSourceUploadRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentStoreRequest;
use App\Http\Requests\Api\Admin\AiAssessments\AiAssessmentUpdateRequest;
use App\Http\Resources\Admin\AiAssessments\AiAssessmentListResource;
use App\Http\Resources\Admin\AiAssessments\AiAssessmentQuestionResource;
use App\Http\Resources\Admin\AiAssessments\AiAssessmentResource;
use App\Http\Resources\Admin\AiAssessments\AiAssessmentSourceResource;
use App\Jobs\GenerateAiAssessmentJob;
use App\Models\Admin;
use App\Models\AiAssessment;
use App\Models\AiAssessmentQuestion;
use App\Models\AiAssessmentSource;
use App\Models\Course;
use App\Services\Admin\CourseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI Assessment builder (D-113): /api/v1/admin/ai-assessments.
 *
 * Authorization, in order:
 *  1. route middleware `section:ai-assessments` (+ the route's ability):
 *     the admin holds `{action}-ai-assessments`; course scope for a body
 *     `course_id` (D-074);
 *  2. AssessmentAccess::find(): the assessment is the caller's own (or the
 *     caller is a super admin) and within its course scope, else 404;
 *  3. only then is the body validated (FormRequests resolved after the
 *     lookup), so another trainer's ids answer 404 whatever is sent.
 */
class AdminAiAssessmentController extends ApiController
{
    public function __construct(
        private readonly AssessmentAccess $access,
        private readonly GenerationService $generation,
        private readonly SourceService $sources,
    ) {}

    /* ---------------------------------------------------------------- *
     | Library                                                          |
     * ---------------------------------------------------------------- */

    public function index(AiAssessmentIndexRequest $request): JsonResponse
    {
        $admin = $this->admin($request);
        $filters = $request->validated();

        $page = $this->access->query($admin)
            ->select([
                'ai_assessments.id', 'ai_assessments.admin_id', 'ai_assessments.course_id', 'ai_assessments.title',
                'ai_assessments.type', 'ai_assessments.content_language', 'ai_assessments.difficulty',
                'ai_assessments.status', 'ai_assessments.version', 'ai_assessments.created_at', 'ai_assessments.updated_at',
            ])
            ->with(['course:id,title', 'admin:id,name'])
            ->withCount([
                'questions',
                'questions as approved_count' => fn ($q) => $q->where('status', 'approved'),
            ])
            ->search($filters['search'] ?? null)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['content_language'] ?? null, fn ($q, $v) => $q->where('content_language', $v))
            ->when($filters['course_id'] ?? null, fn ($q, $v) => $q->where('course_id', $v))
            ->orderByDesc('ai_assessments.updated_at')
            ->orderByDesc('ai_assessments.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated(__('messages.retrieved'), AiAssessmentListResource::collection($page), [
            'usage' => $this->generation->usage($admin),
        ]);
    }

    /** Courses the caller may tag an assessment with (its course scope). */
    public function courses(Request $request): JsonResponse
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $query = Course::query()->select(['id', 'title'])->orderByDesc('id')->limit(200);
        app(CourseScope::class)->constrain($query, $this->admin($request), 'courses.id');
        if ($search !== '') {
            $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return $this->success(__('messages.retrieved'), $query->get()->map(fn (Course $c) => [
            'id' => $c->id, 'title' => $c->title,
        ])->values());
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->generation->usage($this->admin($request)));
    }

    public function store(AiAssessmentStoreRequest $request): JsonResponse
    {
        $assessment = AiAssessment::query()->create($request->payload() + [
            'admin_id' => $this->admin($request)->id,
            'status'   => AiAssessment::STATUS_DRAFT,
        ]);

        return $this->created(__('messages.created'), $this->editor($assessment));
    }

    public function show(Request $request, int $assessment): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $this->generation->resolveStale($model);

        return $this->success(__('messages.retrieved'), $this->editor($model));
    }

    public function update(Request $request, int $assessment): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $data = app(AiAssessmentUpdateRequest::class)->payload();
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $model->fill($data)->save();

        return $this->success(__('messages.updated'), $this->editor($model));
    }

    public function destroy(Request $request, int $assessment): JsonResponse
    {
        $this->sources->deleteAssessment($this->access->find($this->admin($request), $assessment));

        return $this->deleted();
    }

    public function duplicate(Request $request, int $assessment): JsonResponse
    {
        $admin = $this->admin($request);
        $original = $this->access->find($admin, $assessment);
        $asVersion = (bool) (app(AiAssessmentDuplicateRequest::class)->validated()['as_version'] ?? false);

        $copy = DB::transaction(function () use ($original, $admin, $asVersion) {
            $copy = $original->replicate(['status', 'current_run_id', 'generation_started_at', 'last_error']);
            $copy->fill([
                'admin_id'           => $admin->id,
                'duplicated_from_id' => $original->id,
                'title'              => $asVersion ? $original->title : mb_substr($original->title.' ('.__('ai_assessments.copy').')', 0, 200),
                'version'            => $asVersion ? $original->version + 1 : 1,
                'status'             => $original->questions()->exists() ? AiAssessment::STATUS_READY : AiAssessment::STATUS_DRAFT,
            ])->save();

            $now = now();
            $rows = $original->questions()->get()->map(fn (AiAssessmentQuestion $q) => [
                'ai_assessment_id' => $copy->id,
                'run_id'           => null,
                'position'         => $q->position,
                'type'             => $q->type,
                'context'          => $q->context,
                'prompt'           => $q->prompt,
                'options'          => $q->options === null ? null : json_encode($q->options, JSON_UNESCAPED_UNICODE),
                'answer'           => $q->answer === null ? null : json_encode($q->answer, JSON_UNESCAPED_UNICODE),
                'explanation'      => $q->explanation,
                'source_ref'       => $q->source_ref,
                'bloom'            => $q->bloom,
                'difficulty'       => $q->difficulty,
                'status'           => $q->status,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->all();
            if ($rows !== []) {
                AiAssessmentQuestion::query()->insert($rows);
            }

            foreach ($original->sources()->get() as $source) {
                $this->sources->copy($source, $copy);
            }

            return $copy;
        });

        return $this->created(__('ai_assessments.duplicated'), $this->editor($copy));
    }

    /* ---------------------------------------------------------------- *
     | Sources                                                          |
     * ---------------------------------------------------------------- */

    public function uploadSource(Request $request, int $assessment): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $file = app(AiAssessmentSourceUploadRequest::class)->file('file');
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $source = $this->sources->upload($model, $file);

        return $this->created(__('messages.created'), new AiAssessmentSourceResource($this->withChars($source)));
    }

    public function pasteSource(Request $request, int $assessment): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $data = app(AiAssessmentSourceTextRequest::class)->validated();
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $source = $this->sources->paste($model, $data['text'], $data['label'] ?? null);

        return $this->created(__('messages.created'), new AiAssessmentSourceResource($this->withChars($source)));
    }

    public function deleteSource(Request $request, int $assessment, int $source): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $this->sources->delete(AiAssessmentSource::query()->where('ai_assessment_id', $model->id)->findOrFail($source));

        return $this->deleted();
    }

    /* ---------------------------------------------------------------- *
     | Generation                                                       |
     * ---------------------------------------------------------------- */

    public function generate(Request $request, int $assessment): JsonResponse
    {
        $admin = $this->admin($request);
        $model = $this->access->find($admin, $assessment);

        try {
            $run = $this->generation->start($model, $admin);
        } catch (GenerationRefused $e) {
            return $this->refused($e);
        }

        GenerateAiAssessmentJob::dispatchAfterResponse($run->id);

        return $this->success(__('ai_assessments.generation_started'), [
            'assessment' => $this->editor($model),
            'usage'      => $this->generation->usage($admin),
        ], Response::HTTP_ACCEPTED);
    }

    public function regenerateQuestion(Request $request, int $assessment, int $question): JsonResponse
    {
        $admin = $this->admin($request);
        $model = $this->access->find($admin, $assessment);
        $item = $this->question($model, $question);

        try {
            $fresh = $this->generation->regenerateOne($model, $item, $admin);
        } catch (GenerationRefused $e) {
            return $this->refused($e);
        }

        return $this->success(__('ai_assessments.question_regenerated'), [
            'question' => new AiAssessmentQuestionResource($fresh),
            'usage'    => $this->generation->usage($admin),
        ]);
    }

    /* ---------------------------------------------------------------- *
     | Review & edit                                                    |
     * ---------------------------------------------------------------- */

    public function updateQuestion(Request $request, int $assessment, int $question): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $item = $this->question($model, $question);
        $data = app(AiAssessmentQuestionUpdateRequest::class)->payload();
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $item->fill($data)->save();
        $model->touch();

        return $this->success(__('messages.updated'), new AiAssessmentQuestionResource($item));
    }

    public function setQuestionStatus(Request $request, int $assessment, int $question): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $item = $this->question($model, $question);
        $status = app(AiAssessmentQuestionStatusRequest::class)->validated()['status'];

        $item->forceFill(['status' => $status])->save();

        return $this->success(__('messages.updated'), new AiAssessmentQuestionResource($item));
    }

    public function deleteQuestion(Request $request, int $assessment, int $question): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $item = $this->question($model, $question);
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        DB::transaction(function () use ($model, $item) {
            $item->delete();
            AiAssessmentQuestion::query()->where('ai_assessment_id', $model->id)
                ->where('position', '>', $item->position)->decrement('position');
            if (! $model->questions()->exists()) {
                $model->forceFill(['status' => AiAssessment::STATUS_DRAFT])->save();
            } else {
                $model->touch();
            }
        });

        return $this->deleted();
    }

    public function reorder(Request $request, int $assessment): JsonResponse
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $ids = app(AiAssessmentReorderRequest::class)->validated()['ids'];
        if ($refusal = $this->lockedWhileGenerating($model)) {
            return $refusal;
        }

        $current = $model->questions()->pluck('id')->all();
        sort($current);
        $sent = array_map('intval', $ids);
        $sorted = $sent;
        sort($sorted);
        if ($current !== $sorted) {
            return $this->error(__('ai_assessments.validation.reorder_ids'), 422, ['ids' => [__('ai_assessments.validation.reorder_ids')]]);
        }

        // One UPDATE ... CASE for the whole list, not one query per question.
        DB::transaction(function () use ($model, $sent) {
            $cases = [];
            $bindings = [];
            foreach ($sent as $position => $id) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $id, $position + 1);
            }
            DB::update(
                'UPDATE ai_assessment_questions SET position = CASE id '.implode(' ', $cases).' END WHERE ai_assessment_id = ?',
                [...$bindings, $model->id],
            );
            $model->touch();
        });

        return $this->success(__('ai_assessments.reordered'));
    }

    /* ---------------------------------------------------------------- *
     | Export                                                           |
     * ---------------------------------------------------------------- */

    public function export(Request $request, int $assessment, AssessmentExporter $exporter): Response
    {
        $model = $this->access->find($this->admin($request), $assessment);
        $data = app(AiAssessmentExportRequest::class)->validated();

        if ($model->isGenerating() || ! $model->questions()->exists()) {
            return $this->error(__('ai_assessments.errors.'.($model->isGenerating() ? 'generating_locked' : 'no_questions')), 422);
        }

        return $exporter->export($model, $data['format'], $data['variant']);
    }

    /* ---------------------------------------------------------------- */

    private function admin(Request $request): Admin
    {
        /** @var Admin */
        return $request->user();
    }

    private function question(AiAssessment $assessment, int $id): AiAssessmentQuestion
    {
        return AiAssessmentQuestion::query()->where('ai_assessment_id', $assessment->id)->findOrFail($id);
    }

    private function lockedWhileGenerating(AiAssessment $assessment): ?JsonResponse
    {
        $this->generation->resolveStale($assessment);

        return $assessment->isGenerating()
            ? $this->error(__('ai_assessments.errors.generating_locked'), 409)
            : null;
    }

    private function refused(GenerationRefused $e): JsonResponse
    {
        return $this->error(__('ai_assessments.errors.'.$e->reason), $e->status, ['reason' => [$e->reason]]);
    }

    /** The editor payload with bounded loads: no source text, no provider URI. */
    private function editor(AiAssessment $assessment): AiAssessmentResource
    {
        $assessment->load([
            'course:id,title',
            'questions',
            'sources' => fn ($q) => $q->select([
                'id', 'ai_assessment_id', 'kind', 'name', 'size', 'units', 'created_at',
                DB::raw('CHAR_LENGTH(COALESCE(extracted_text, \'\')) as chars'),
            ]),
        ]);

        return new AiAssessmentResource($assessment);
    }

    private function withChars(AiAssessmentSource $source): AiAssessmentSource
    {
        $source->setAttribute('chars', mb_strlen((string) $source->extracted_text));

        return $source;
    }
}
