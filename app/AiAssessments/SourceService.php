<?php

namespace App\AiAssessments;

use App\AiAssessments\Sources\SourceReader;
use App\AiAssessments\Sources\SourceReadException;
use App\Models\AiAssessment;
use App\Models\AiAssessmentSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Source material of an assessment (D-113).
 *
 * Uploads: the kind comes from the bytes (SourceReader::detect), and the
 * name's extension must agree with it. The file is stored on the private
 * disk under a generated name; nothing is ever served back (the builder only
 * needs the text and the provider copy). Count and text limits are checked
 * under a lock on the assessment, so parallel uploads cannot pass them.
 */
final class SourceService
{
    private const DISK = 'private';

    public function __construct(private readonly SourceReader $reader) {}

    public function upload(AiAssessment $assessment, UploadedFile $file): AiAssessmentSource
    {
        $kind = $this->reader->detect($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());

        if ($kind === null || $kind !== $extension) {
            throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.type')]);
        }

        $text = null;
        $units = null;
        if ($kind !== 'pdf') {
            try {
                ['text' => $text, 'units' => $units] = $this->reader->extract($file->getRealPath(), $kind);
            } catch (SourceReadException $e) {
                throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.'.$e->getMessage())]);
            }
            if (trim($text) === '') {
                throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.empty')]);
            }
        }

        $name = $this->displayName($file->getClientOriginalName());

        return DB::transaction(function () use ($assessment, $file, $kind, $text, $units, $name) {
            $this->assertRoom($assessment, $text);

            $path = $file->storeAs('ai-assessments/'.$assessment->id, Str::uuid()->toString().'.'.$kind, self::DISK);
            if ($path === false) {
                throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.unreadable')]);
            }

            return $assessment->sources()->create([
                'kind'           => $kind,
                'name'           => $name,
                'path'           => $path,
                'size'           => (int) $file->getSize(),
                'extracted_text' => $text,
                'units'          => $units,
            ]);
        });
    }

    public function paste(AiAssessment $assessment, string $text, ?string $label): AiAssessmentSource
    {
        $text = trim(str_replace("\r\n", "\n", $text));

        return DB::transaction(function () use ($assessment, $text, $label) {
            $this->assertRoom($assessment, $text);
            $number = $assessment->sources()->where('kind', 'text')->count() + 1;

            return $assessment->sources()->create([
                'kind'           => 'text',
                'name'           => filled($label) ? mb_substr(trim($label), 0, 120) : __('ai_assessments.pasted_text', ['n' => $number]),
                'size'           => strlen($text),
                'extracted_text' => $text,
                'units'          => null,
            ]);
        });
    }

    public function delete(AiAssessmentSource $source): void
    {
        $path = $source->path;
        $source->delete();
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Remove an assessment and every stored file it owns. */
    public function deleteAssessment(AiAssessment $assessment): void
    {
        $paths = $assessment->sources()->whereNotNull('path')->pluck('path')->all();
        $assessment->delete();
        Storage::disk(self::DISK)->delete($paths);
        Storage::disk(self::DISK)->deleteDirectory('ai-assessments/'.$assessment->id);
    }

    /** Copy a source to another assessment (duplicate), with its own file. */
    public function copy(AiAssessmentSource $source, AiAssessment $to): void
    {
        $path = null;
        $disk = Storage::disk(self::DISK);
        if ($source->path && $disk->exists($source->path)) {
            $path = 'ai-assessments/'.$to->id.'/'.Str::uuid()->toString().'.'.$source->kind;
            $disk->copy($source->path, $path);
        }

        $to->sources()->create([
            'kind'                     => $source->kind,
            'name'                     => $source->name,
            'path'                     => $path,
            'size'                     => $source->size,
            'extracted_text'           => $source->extracted_text,
            'units'                    => $source->units,
            'provider_file_uri'        => $source->provider_file_uri,
            'provider_file_expires_at' => $source->provider_file_expires_at,
        ]);
    }

    private function assertRoom(AiAssessment $assessment, ?string $newText): void
    {
        AiAssessment::query()->whereKey($assessment->id)->lockForUpdate()->value('id');

        if ($assessment->sources()->count() >= (int) config('ai-assessments.limits.sources')) {
            throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.too_many', [
                'max' => config('ai-assessments.limits.sources'),
            ])]);
        }

        if ($newText !== null) {
            $used = (int) $assessment->sources()->sum(DB::raw('CHAR_LENGTH(COALESCE(extracted_text, \'\'))'));
            if ($used + mb_strlen($newText) > (int) config('ai-assessments.limits.source_chars')) {
                throw ValidationException::withMessages(['file' => __('ai_assessments.source_errors.too_much_text')]);
            }
        }
    }

    /** The original name, reduced to something safe to display and print. */
    private function displayName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? '';

        return mb_substr(trim($name), 0, 200) ?: 'source';
    }
}
