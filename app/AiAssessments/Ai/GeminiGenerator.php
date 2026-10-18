<?php

namespace App\AiAssessments\Ai;

use App\Models\AiAssessmentSource;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Google Gemini API driver (H1).
 *
 *  - Structured output: `responseMimeType: application/json` + a per-type
 *    `responseSchema`, so the answer is parseable JSON, never prose.
 *  - PDFs go through the Files API (one upload, reused for 47 h; the API keeps
 *    files 48 h), streamed from the private disk rather than read into memory.
 *  - The key travels only in the `x-goog-api-key` header (never in a URL, so
 *    it cannot reach a log line). Failures are logged with the HTTP status and
 *    the provider's error status only: no key, no prompt, no course text.
 *  - 429 / 5xx / connection errors are retried twice with back-off.
 */
final class GeminiGenerator implements QuestionGenerator
{
    public function __construct(private readonly PromptBuilder $prompts) {}

    public function isConfigured(): bool
    {
        return filled(config('ai-assessments.gemini.api_key'));
    }

    public function model(): string
    {
        return (string) config('ai-assessments.gemini.model');
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        if (! $this->isConfigured()) {
            throw new GenerationFailed(GenerationFailed::NOT_CONFIGURED);
        }

        $parts = [];
        foreach ($request->sources as $source) {
            if ($source->kind === 'pdf') {
                $parts[] = ['file_data' => ['mime_type' => 'application/pdf', 'file_uri' => $this->pdfUri($source)]];
            }
        }
        $parts[] = ['text' => $this->prompts->request($request)];

        $response = $this->send(fn (PendingRequest $http) => $http->post(
            $this->base().'/v1beta/models/'.rawurlencode($this->model()).':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => $this->prompts->system($request->assessment->content_language)]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => $this->prompts->schema($request->type),
                    'temperature'      => (float) config('ai-assessments.gemini.temperature'),
                ],
            ],
        ));

        $body = $response->json() ?? [];

        if (isset($body['promptFeedback']['blockReason'])) {
            throw new GenerationFailed(GenerationFailed::BLOCKED);
        }

        $candidate = $body['candidates'][0] ?? null;
        $finish = $candidate['finishReason'] ?? null;
        if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            throw new GenerationFailed(GenerationFailed::BLOCKED);
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= is_string($part['text'] ?? null) ? $part['text'] : '';
        }

        try {
            $decoded = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // MAX_TOKENS cuts the JSON short; anything else is malformed.
            throw new GenerationFailed(GenerationFailed::INVALID_OUTPUT, (string) $finish);
        }

        $questions = is_array($decoded['questions'] ?? null) ? array_values(array_filter($decoded['questions'], 'is_array')) : [];

        return new GenerationResult(
            $questions,
            (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
        );
    }

    /** The Files API URI of a PDF source, uploading it when there is no live copy. */
    private function pdfUri(AiAssessmentSource $source): string
    {
        if ($source->provider_file_uri && $source->provider_file_expires_at?->isFuture()) {
            return $source->provider_file_uri;
        }

        $disk = Storage::disk('private');
        if (! $source->path || ! $disk->exists($source->path)) {
            throw new GenerationFailed(GenerationFailed::SOURCE_UNAVAILABLE);
        }
        $size = $disk->size($source->path);

        $start = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders([
                'X-Goog-Upload-Protocol'            => 'resumable',
                'X-Goog-Upload-Command'             => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => 'application/pdf',
            ])
            ->post($this->base().'/upload/v1beta/files', ['file' => ['display_name' => 'source-'.$source->id]]));

        $uploadUrl = (string) $start->header('X-Goog-Upload-URL');
        if (! str_starts_with($uploadUrl, $this->base().'/')) {
            // Only ever follow an upload URL on the configured API host.
            throw new GenerationFailed(GenerationFailed::PROVIDER_ERROR, 'upload url');
        }

        $stream = $disk->readStream($source->path);
        try {
            $uploaded = $this->send(fn (PendingRequest $http) => $http
                ->withHeaders([
                    'X-Goog-Upload-Offset'  => '0',
                    'X-Goog-Upload-Command' => 'upload, finalize',
                ])
                ->withBody(Utils::streamFor($stream), 'application/pdf')
                ->post($uploadUrl), retry: false);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $file = $uploaded->json('file') ?? [];
        $file = $this->waitUntilActive($file);

        $source->forceFill([
            'provider_file_uri'        => $file['uri'],
            'provider_file_expires_at' => Carbon::now()->addHours(47),
        ])->save();

        return $file['uri'];
    }

    /**
     * A PDF is PROCESSING for a few seconds after upload; poll briefly.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function waitUntilActive(array $file): array
    {
        for ($i = 0; $i < 15 && ($file['state'] ?? null) === 'PROCESSING'; $i++) {
            usleep(2_000_000);
            $name = (string) ($file['name'] ?? '');
            if (! preg_match('#^files/[A-Za-z0-9_-]+$#', $name)) {
                break;
            }
            $file = $this->send(fn (PendingRequest $http) => $http->get($this->base().'/v1beta/'.$name))->json() ?? [];
        }

        if (($file['state'] ?? 'ACTIVE') !== 'ACTIVE' || ! is_string($file['uri'] ?? null)) {
            throw new GenerationFailed(GenerationFailed::SOURCE_UNAVAILABLE, (string) ($file['state'] ?? ''));
        }

        return $file;
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, bool $retry = true): Response
    {
        $http = Http::withHeaders(['x-goog-api-key' => (string) config('ai-assessments.gemini.api_key')])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('ai-assessments.gemini.timeout'));

        if ($retry) {
            $http = $http->retry(3, fn (int $attempt) => $attempt * 2000, function (\Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException
                        && ($e->response->status() === 429 || $e->response->serverError()));
            }, throw: false);
        }

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new GenerationFailed(GenerationFailed::TIMEOUT);
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('ai-assessments: provider call failed', [
            'http_status'     => $response->status(),
            'provider_status' => $response->json('error.status'),
        ]);

        throw new GenerationFailed(match (true) {
            in_array($response->status(), [401, 403], true) => GenerationFailed::PROVIDER_AUTH,
            $response->status() === 429 => GenerationFailed::PROVIDER_BUSY,
            default => GenerationFailed::PROVIDER_ERROR,
        }, (string) $response->status());
    }

    private function base(): string
    {
        return rtrim((string) config('ai-assessments.gemini.base_url'), '/');
    }
}
