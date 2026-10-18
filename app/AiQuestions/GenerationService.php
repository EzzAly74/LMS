<?php

namespace App\AiQuestions;

use App\AiQuestions\Ai\GenerationFailed;
use App\AiQuestions\Ai\GenerationRequest;
use App\AiQuestions\Ai\QuestionGenerator;
use App\AiQuestions\Sources\SourceInput;
use App\AiQuestions\Sources\SourceReader;
use App\AiQuestions\Sources\SourceReadException;
use App\Models\Admin;
use App\Models\AiGeneration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Generate with AI" in the quiz and assignment forms (D-113).
 *
 *  start(): reads the sources (kind from the bytes; DOCX / PPTX text with
 *           page / slide markers; PDFs kept on the private disk for the
 *           provider), then, under a lock on the admin, checks the daily cap
 *           and that no other generation of theirs is running, and records
 *           the run as queued. It is executed once by run(): by a queue
 *           worker (GenerateAiQuestionsJob) or, without one, by the first poll.
 *  run():   one provider call for every requested type; the answer is kept
 *           only in the system question shape (SystemQuestion). Source text
 *           and files are removed when the run ends, success or not.
 *  find():  the caller's own generation (404 for anyone else's), with a run
 *           older than `stale_after_minutes` reported as failed.
 */
final class GenerationService
{
    private const DISK = 'private';

    public function __construct(
        private readonly QuestionGenerator $generator,
        private readonly SourceReader $reader,
    ) {}

    /** @return array{used:int, limit:int, configured:bool} */
    public function usage(Admin $admin): array
    {
        return [
            'used'       => $this->runsToday($admin),
            'limit'      => (int) config('ai-questions.daily_run_limit'),
            'configured' => $this->generator->isConfigured(),
        ];
    }

    /**
     * @param  'quiz'|'assignment'  $target
     * @param  array<string, mixed>  $settings  validated: counts, difficulty, bloom_levels, objectives, assessment_type, score
     * @param  list<UploadedFile>  $files
     *
     * @throws GenerationRefused|ValidationException
     */
    public function start(Admin $admin, string $target, ?int $courseId, array $settings, array $files, ?string $text): AiGeneration
    {
        if (! $this->generator->isConfigured()) {
            throw new GenerationRefused(GenerationFailed::NOT_CONFIGURED, 503);
        }

        // Cheap checks before reading any file.
        $this->assertCanRun($admin, lock: false);

        [$names, $texts, $pdfs] = $this->readSources($files, $text);

        try {
            return DB::transaction(function () use ($admin, $target, $courseId, $settings, $names, $texts, $pdfs) {
                $this->assertCanRun($admin, lock: true);

                return AiGeneration::query()->create([
                    'admin_id'     => $admin->id,
                    'target'       => $target,
                    'course_id'    => $courseId,
                    'status'       => AiGeneration::STATUS_QUEUED,
                    'settings'     => $settings,
                    'source_names' => $names,
                    'source_text'  => json_encode($texts, JSON_UNESCAPED_UNICODE),
                    'source_files' => $pdfs,
                    'model'        => $this->generator->model(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete(array_column($pdfs, 'path'));
            throw $e;
        }
    }

    /**
     * Where a queued run is executed. With a real queue (QUEUE_CONNECTION
     * other than sync) a worker takes GenerateAiQuestionsJob. Without one the
     * first status poll runs it: Start then answers at once on every server
     * (PHP's built-in server cannot send a response before an after-response
     * job finishes), and the dialog shows progress while the poll works.
     */
    public function runsOnPoll(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * Execute a queued run, once: the queued -> running claim is a single
     * conditional UPDATE, so parallel polls, retries or a worker can never run
     * (and pay for) it twice. Never throws: the outcome is stored.
     */
    public function run(int $id): void
    {
        $claimed = AiGeneration::query()->whereKey($id)->where('status', AiGeneration::STATUS_QUEUED)
            ->update(['status' => AiGeneration::STATUS_RUNNING, 'updated_at' => Carbon::now()]);
        $generation = $claimed ? AiGeneration::query()->find($id) : null;
        if ($generation === null) {
            return;
        }

        // The run may outlive the request that started it (poll mode) or the
        // client that waits for it: finish and store the outcome regardless.
        @set_time_limit((int) config('ai-questions.gemini.timeout') + 120);
        ignore_user_abort(true);

        try {
            $counts = $generation->counts();
            $result = $this->generator->generate(new GenerationRequest(
                $generation->target,
                $generation->settings['assessment_type'] ?? null,
                $counts,
                (string) ($generation->settings['difficulty'] ?? 'mixed'),
                array_values((array) ($generation->settings['bloom_levels'] ?? [])),
                $generation->settings['objectives'] ?? null,
                $this->sourcesOf($generation),
            ));

            $questions = SystemQuestion::normalize($counts, $result->questions, (int) ($generation->settings['score'] ?? 1));
            if ($questions === []) {
                throw new GenerationFailed(GenerationFailed::INVALID_OUTPUT);
            }

            $this->finish($generation, AiGeneration::STATUS_SUCCEEDED, null, $result->inputTokens, $result->outputTokens, $questions);
        } catch (GenerationFailed $e) {
            $this->finish($generation, AiGeneration::STATUS_FAILED, $e->reason);
        } catch (\Throwable $e) {
            Log::error('ai-questions: generation crashed', ['generation' => $generation->id, 'exception' => $e::class]);
            $this->finish($generation, AiGeneration::STATUS_FAILED, GenerationFailed::PROVIDER_ERROR);
        }
    }

    public function find(Admin $admin, int $id, string $target): AiGeneration
    {
        $generation = AiGeneration::query()
            ->whereKey($id)
            ->where('admin_id', $admin->id)
            ->where('target', $target)
            ->firstOrFail();

        $limit = Carbon::now()->subMinutes((int) config('ai-questions.stale_after_minutes'));
        if ($generation->isRunning() && $generation->created_at->lt($limit)) {
            $this->finish($generation, AiGeneration::STATUS_FAILED, GenerationFailed::TIMEOUT);
        }

        return $generation;
    }

    /** @throws GenerationRefused */
    private function assertCanRun(Admin $admin, bool $lock): void
    {
        if ($lock) {
            // Serialise per admin: two parallel clicks cannot both pass.
            Admin::query()->whereKey($admin->id)->lockForUpdate()->value('id');
        }
        if ($this->runsToday($admin) >= (int) config('ai-questions.daily_run_limit')) {
            throw new GenerationRefused('daily_limit', 429);
        }
        $stale = Carbon::now()->subMinutes((int) config('ai-questions.stale_after_minutes'));
        $running = AiGeneration::query()->where('admin_id', $admin->id)
            ->whereIn('status', AiGeneration::ACTIVE)->where('created_at', '>=', $stale)->exists();
        if ($running) {
            throw new GenerationRefused('busy', 409);
        }
    }

    private function runsToday(Admin $admin): int
    {
        return AiGeneration::query()->where('admin_id', $admin->id)->where('created_at', '>=', Carbon::today())->count();
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{0: list<array{name:string, kind:string}>, 1: list<array{name:string, kind:string, text:string}>, 2: list<array{name:string, path:string}>}
     *
     * @throws ValidationException
     */
    private function readSources(array $files, ?string $text): array
    {
        $names = [];
        $texts = [];
        $pdfs = [];
        $chars = 0;

        foreach ($files as $i => $file) {
            $kind = $this->reader->detect($file->getRealPath());
            if ($kind === null || $kind !== strtolower($file->getClientOriginalExtension())) {
                throw ValidationException::withMessages(["files.$i" => __('ai_questions.source_errors.type', ['name' => $this->displayName($file)])]);
            }
            $name = $this->displayName($file);
            $names[] = ['name' => $name, 'kind' => $kind];

            if ($kind === 'pdf') {
                $path = $file->storeAs('ai-generations', Str::uuid()->toString().'.pdf', self::DISK);
                if ($path === false) {
                    throw ValidationException::withMessages(["files.$i" => __('ai_questions.source_errors.unreadable', ['name' => $name])]);
                }
                $pdfs[] = ['name' => $name, 'path' => $path];

                continue;
            }

            try {
                ['text' => $extracted] = $this->reader->extract($file->getRealPath(), $kind);
            } catch (SourceReadException $e) {
                throw ValidationException::withMessages(["files.$i" => __('ai_questions.source_errors.'.$e->getMessage(), ['name' => $name])]);
            }
            if (trim($extracted) === '') {
                throw ValidationException::withMessages(["files.$i" => __('ai_questions.source_errors.empty', ['name' => $name])]);
            }
            $chars += mb_strlen($extracted);
            $texts[] = ['name' => $name, 'kind' => $kind, 'text' => $extracted];
        }

        $pasted = trim(str_replace("\r\n", "\n", (string) $text));
        if ($pasted !== '') {
            $chars += mb_strlen($pasted);
            $names[] = ['name' => __('ai_questions.pasted_text'), 'kind' => 'text'];
            $texts[] = ['name' => __('ai_questions.pasted_text'), 'kind' => 'text', 'text' => $pasted];
        }

        if ($chars > (int) config('ai-questions.limits.source_chars')) {
            Storage::disk(self::DISK)->delete(array_column($pdfs, 'path'));
            throw ValidationException::withMessages(['text' => __('ai_questions.source_errors.too_much_text')]);
        }

        return [$names, $texts, $pdfs];
    }

    /** @return list<SourceInput> */
    private function sourcesOf(AiGeneration $generation): array
    {
        $sources = [];
        foreach ((array) json_decode((string) $generation->source_text, true) as $t) {
            $sources[] = SourceInput::text((string) $t['name'], (string) $t['text'], (string) $t['kind']);
        }
        foreach ((array) $generation->source_files as $f) {
            $sources[] = SourceInput::pdf((string) $f['name'], (string) $f['path']);
        }

        return $sources;
    }

    /** @param  list<array<string, mixed>>|null  $questions */
    private function finish(AiGeneration $generation, string $status, ?string $error, int $in = 0, int $out = 0, ?array $questions = null): void
    {
        Storage::disk(self::DISK)->delete(array_column((array) $generation->source_files, 'path'));

        $generation->forceFill([
            'status'        => $status,
            'error'         => $error,
            'questions'     => $questions,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'source_text'   => null,
            'source_files'  => null,
            'finished_at'   => Carbon::now(),
        ])->save();
    }

    /** The original name, reduced to something safe to display. */
    private function displayName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $file->getClientOriginalName()) ?? '';

        return mb_substr(trim($name), 0, 200) ?: 'source';
    }
}
