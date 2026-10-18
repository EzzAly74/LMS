<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\Admin;
use App\Models\AiAssessment;
use App\Models\AiAssessmentQuestion;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;
use ZipArchive;

/**
 * Shared set-up for the AI Assessment builder tests (D-113). The Gemini API
 * is always faked: no test reaches the network, and none needs a real key.
 */
abstract class AiAssessmentTestCase extends ApiTestCase
{
    protected const URL = self::BASE.'/admin/ai-assessments';

    protected const CRUD = ['view-ai-assessments', 'create-ai-assessments', 'edit-ai-assessments', 'delete-ai-assessments'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config([
            'ai-assessments.gemini.api_key'  => 'test-key-not-real',
            'ai-assessments.gemini.base_url' => 'https://gemini.test',
            'ai-assessments.gemini.model'    => 'gemini-test',
        ]);
        Http::preventStrayRequests();
        // The client's retry back-off must not slow the suite.
        Sleep::fake();
    }

    /** @return array{model: Admin, headers: array<string,string>} */
    protected function trainer(array $permissions = self::CRUD, string $scope = 'all', ?int $instructorId = null): array
    {
        $role = Role::findOrCreate('ai-test-'.uniqid(), 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => $scope]);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'admin'));
        }
        $admin = Admin::factory()->create();
        if ($instructorId !== null) {
            $admin->forceFill(['instructor_id' => $instructorId])->save();
        }
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    /** @return array{model: Admin, headers: array<string,string>} */
    protected function superAdmin(): array
    {
        $admin = Admin::factory()->create();
        $admin->assignRole(Role::findOrCreate('superAdmin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    protected function assessment(Admin $owner, array $attributes = []): AiAssessment
    {
        return AiAssessment::query()->create($attributes + [
            'admin_id'         => $owner->id,
            'title'            => 'Workplace safety basics',
            'type'             => 'post',
            'content_language' => 'en',
            'difficulty'       => 'mixed',
            'question_counts'  => ['mcq' => 2, 'true_false' => 1],
            'status'           => AiAssessment::STATUS_DRAFT,
        ]);
    }

    protected function withText(AiAssessment $assessment, ?string $text = null): AiAssessment
    {
        $assessment->sources()->create([
            'kind' => 'text', 'name' => 'Notes', 'size' => 100,
            'extracted_text' => $text ?? "[Page 1]\nAlways wear a helmet on site. Fire exits must stay clear. Report every near miss within 24 hours.",
        ]);

        return $assessment;
    }

    protected function question(AiAssessment $assessment, array $attributes = []): AiAssessmentQuestion
    {
        $position = (int) $assessment->questions()->max('position') + 1;

        return $assessment->questions()->create($attributes + [
            'position'    => $position,
            'type'        => 'mcq',
            'prompt'      => 'What must stay clear at all times? '.$position,
            'options'     => ['Fire exits', 'Desks', 'Parking', 'Windows'],
            'answer'      => ['indexes' => [0]],
            'explanation' => 'The handbook says fire exits must stay clear.',
            'source_ref'  => 'Notes, Page 1',
            'bloom'       => 'remember',
            'difficulty'  => 'easy',
            'status'      => 'pending',
        ]);
    }

    /* ---------------- provider fakes ---------------- */

    /** A Gemini generateContent body holding `$questions` as the JSON answer. */
    protected function geminiBody(array $questions, int $in = 1200, int $out = 300): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE)]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out],
        ];
    }

    protected function mcqItem(string $prompt = 'Which item must stay clear?', array $correct = [0]): array
    {
        return [
            'prompt' => $prompt, 'options' => ['Fire exits', 'Desks', 'Parking', 'Windows'],
            'correct_indexes' => $correct, 'explanation' => 'From the notes.', 'source_ref' => 'Notes, Page 1',
            'bloom' => 'remember', 'difficulty' => 'easy',
        ];
    }

    protected function trueFalseItem(string $prompt = 'Near misses must be reported within 24 hours.'): array
    {
        return [
            'prompt' => $prompt, 'correct' => true, 'explanation' => 'Stated on page 1.',
            'source_ref' => 'Notes, Page 1', 'bloom' => 'understand', 'difficulty' => 'medium',
        ];
    }

    /**
     * Answer each generateContent call with the next body (by question type
     * order: QuestionType::ALL), anything else 404.
     *
     * @param  list<array|int>  $bodies  a body, or an HTTP status to fail with
     */
    protected function fakeGemini(array $bodies): void
    {
        // Http::fake() stubs accumulate (the first match wins), so one stub
        // reads a queue that each call to fakeGemini() replaces.
        $this->geminiQueue = $bodies;
        if ($this->geminiFaked) {
            return;
        }
        $this->geminiFaked = true;

        Http::fake([
            'gemini.test/v1beta/models/*' => function () {
                $next = array_shift($this->geminiQueue);
                if ($next === null) {
                    return Http::response(['error' => ['status' => 'UNEXPECTED_CALL']], 418);
                }

                return is_int($next) ? Http::response(['error' => ['status' => 'X']], $next) : Http::response($next, 200);
            },
            '*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);
    }

    /** @var list<array|int> */
    private array $geminiQueue = [];

    private bool $geminiFaked = false;

    /** @return list<HttpRequest> */
    protected function generateCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), ':generateContent'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    /* ---------------- source files ---------------- */

    protected function docx(array $pages, string $name = 'handbook.docx'): UploadedFile
    {
        $body = '';
        foreach ($pages as $i => $text) {
            if ($i > 0) {
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $body .= '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';

        return $this->zipUpload($name, ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => $xml]);
    }

    protected function pptx(array $slides, string $name = 'deck.pptx'): UploadedFile
    {
        $entries = ['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<p:presentation xmlns:p="x"/>'];
        foreach ($slides as $i => $text) {
            $entries['ppt/slides/slide'.($i + 1).'.xml'] = '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'
                .htmlspecialchars($text, ENT_XML1).'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        }

        return $this->zipUpload($name, $entries);
    }

    protected function pdf(string $name = 'policy.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aip');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** @param  array<string, string>  $entries */
    protected function zipUpload(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aiz');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }
}
