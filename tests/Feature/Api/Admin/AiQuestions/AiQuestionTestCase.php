<?php

namespace Tests\Feature\Api\Admin\AiQuestions;

use App\Models\Admin;
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
 * "Generate with AI" in the quiz and assignment forms (D-113). The Gemini
 * API is always faked: no test reaches the network or needs a real key.
 */
abstract class AiQuestionTestCase extends ApiTestCase
{
    protected const QUIZ = self::BASE.'/admin/quizzes/ai-generations';
    protected const ASSIGNMENT = self::BASE.'/admin/assignments/ai-generations';

    protected const NOTES = "[Page 1]\nAlways wear a helmet on site. Fire exits must stay clear at all times. Report every near miss within 24 hours.";

    /** @var list<array|int> */
    private array $geminiQueue = [];

    private bool $geminiFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config([
            'ai-questions.gemini.api_key'  => 'test-key-not-real',
            'ai-questions.gemini.base_url' => 'https://gemini.test',
            'ai-questions.gemini.model'    => 'gemini-test',
            // No fallback by default; AiQuestionFallbackTest covers it.
            'ai-questions.gemini.fallback_models' => '',
        ]);
        Http::preventStrayRequests();
        Sleep::fake(); // the client's retry back-off must not slow the suite
    }

    /** @return array{model: Admin, headers: array<string,string>} */
    protected function trainer(array $permissions = ['view-quizzes', 'create-quizzes', 'edit-quizzes', 'view-assignments', 'create-assignments', 'edit-assignments'], string $scope = 'all', ?int $instructorId = null): array
    {
        $role = Role::findOrCreate('ai-q-'.uniqid(), 'admin');
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

    /** A valid start body: pasted notes, 2 MCQ + 1 yes/no. */
    protected function body(array $override = []): array
    {
        return $override + [
            'text'            => self::NOTES,
            'counts'          => ['mcq' => 2, 'yes_no' => 1],
            'difficulty'      => 'mixed',
            'assessment_type' => 'post',
            'score'           => 5,
        ];
    }

    /* ---------------- provider fakes ---------------- */

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

    protected function common(string $type, string $en, string $ar): array
    {
        return [
            'type' => $type, 'question_en' => $en, 'question_ar' => $ar,
            'explanation_en' => 'From the notes.', 'explanation_ar' => 'من الملاحظات.',
            'source_ref' => 'Notes, Page 1', 'bloom' => 'remember', 'difficulty' => 'easy',
        ];
    }

    protected function mcq(string $en = 'What must stay clear?', int $correct = 0): array
    {
        return $this->common('mcq', $en, 'ما الذي يجب أن يبقى خاليًا؟') + [
            'options_en' => ['Fire exits', 'Desks', 'Parking', 'Windows'],
            'options_ar' => ['مخارج الطوارئ', 'المكاتب', 'المواقف', 'النوافذ'],
            'correct_index' => $correct,
        ];
    }

    protected function yesNo(string $en = 'Must near misses be reported within 24 hours?', bool $yes = true): array
    {
        return $this->common('yes_no', $en, 'هل يجب الإبلاغ عن الحوادث الوشيكة خلال 24 ساعة؟') + ['correct_yes' => $yes];
    }

    protected function open(): array
    {
        return $this->common('open', 'Why report near misses?', 'لماذا نبلغ عن الحوادث الوشيكة؟') + [
            'model_answer_en' => 'To prevent the next accident.', 'model_answer_ar' => 'لمنع الحادث التالي.',
        ];
    }

    protected function reorder(): array
    {
        return $this->common('reorder', 'Order the evacuation steps.', 'رتّب خطوات الإخلاء.') + [
            'options_en' => ['Stop work', 'Leave by the nearest exit', 'Go to the assembly point'],
            'options_ar' => ['أوقف العمل', 'غادر من أقرب مخرج', 'اتجه إلى نقطة التجمع'],
        ];
    }

    /**
     * Answer each generateContent call with the next entry (a body, or an
     * HTTP status to fail with); one stub reads a queue each call replaces.
     *
     * @param  list<array|int>  $bodies
     */
    protected function fakeGemini(array $bodies): void
    {
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

        return $this->zipUpload($name, [
            '[Content_Types].xml' => '<Types/>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>',
        ]);
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
