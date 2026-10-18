<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\AiAssessmentSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Uploads are identified by content, extracted with page / slide markers, bounded and cleaned up. */
class AiAssessmentSourceTest extends AiAssessmentTestCase
{
    public function test_a_docx_is_read_page_by_page(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);

        $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->docx(['Helmets are required.', 'السلامة أولًا في كل موقع.'])], $t['headers'])
            ->assertCreated()
            ->assertJsonPath('result.kind', 'docx')
            ->assertJsonPath('result.name', 'handbook.docx')
            ->assertJsonPath('result.units', 2)
            ->assertJsonMissingPath('result.path')
            ->assertJsonMissingPath('result.extracted_text');

        $source = AiAssessmentSource::query()->sole();
        $this->assertSame("[Page 1]\nHelmets are required.\n\n[Page 2]\nالسلامة أولًا في كل موقع.", $source->extracted_text);
        Storage::disk('private')->assertExists($source->path);
        $this->assertMatchesRegularExpression('#^ai-assessments/'.$a->id.'/[0-9a-f-]{36}\.docx$#', $source->path, 'server-generated name');
    }

    public function test_a_pptx_is_read_slide_by_slide_in_order(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $slides = array_map(fn ($i) => "Slide text {$i}", range(1, 11));

        $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->pptx($slides)], $t['headers'])->assertCreated()
            ->assertJsonPath('result.units', 11);

        $text = AiAssessmentSource::query()->sole()->extracted_text;
        $this->assertStringStartsWith("[Slide 1]\nSlide text 1", $text);
        $this->assertLessThan(strpos($text, '[Slide 10]'), strpos($text, '[Slide 2]'), 'numeric, not alphabetical, slide order');
    }

    public function test_a_pdf_is_stored_for_the_provider_without_local_parsing(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);

        $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->pdf()], $t['headers'])->assertCreated()
            ->assertJsonPath('result.kind', 'pdf')->assertJsonPath('result.chars', 0);

        $this->assertNull(AiAssessmentSource::query()->sole()->extracted_text);
    }

    public function test_files_are_identified_by_content_not_by_name(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $h = $t['headers'];
        $url = self::URL."/{$a->id}/sources";

        // A Word file renamed .pdf, a plain zip named .docx, a script named .pptx, an .exe.
        $this->postJson($url, ['file' => $this->docx(['x'], 'renamed.pdf')], $h)->assertStatus(422);
        $this->postJson($url, ['file' => $this->zipUpload('fake.docx', ['readme.txt' => 'hi'])], $h)->assertStatus(422);
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('slides.pptx', '<?php echo 1;')], $h)->assertStatus(422);
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00")], $h)->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('ai_assessment_sources', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_oversized_empty_and_hostile_documents_are_refused(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $url = self::URL."/{$a->id}/sources";

        config(['ai-assessments.limits.file_kb' => 1]);
        $this->postJson($url, ['file' => UploadedFile::fake()->create('big.pdf', 5, 'application/pdf')], $t['headers'])->assertStatus(422);
        config(['ai-assessments.limits.file_kb' => 20480]);

        $this->postJson($url, ['file' => $this->docx([''])], $t['headers'])->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('ai_assessments.source_errors.empty'));

        // An XML external entity is never resolved (XXE).
        $xxe = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;Safe text</w:t></w:r></w:p></w:body></w:document>';
        $this->postJson($url, ['file' => $this->zipUpload('xxe.docx', ['word/document.xml' => $xxe])], $t['headers'])->assertCreated();
        $this->assertStringNotContainsString('root:', (string) AiAssessmentSource::query()->latest('id')->value('extracted_text'));

        // A part bigger than the read limit (zip bomb guard).
        config(['ai-assessments.limits.zip_entry_bytes' => 100]);
        $this->postJson($url, ['file' => $this->docx([str_repeat('A', 500)])], $t['headers'])->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('ai_assessments.source_errors.too_large'));
    }

    public function test_source_count_and_total_text_are_capped(): void
    {
        config(['ai-assessments.limits.sources' => 2, 'ai-assessments.limits.source_chars' => 150]);
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $url = self::URL."/{$a->id}/sources/text";

        $this->postJson($url, ['text' => str_repeat('a', 100)], $t['headers'])->assertCreated()
            ->assertJsonPath('result.name', 'Pasted text 1')->assertJsonPath('result.chars', 100);
        $this->postJson($url, ['text' => str_repeat('b', 60)], $t['headers'])->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('ai_assessments.source_errors.too_much_text'));
        $this->postJson($url, ['text' => str_repeat('c', 50), 'label' => 'Glossary'], $t['headers'])->assertCreated();
        $this->postJson($url, ['text' => str_repeat('d', 50)], $t['headers'])->assertStatus(422);
        $this->assertSame(2, $a->sources()->count());
    }

    public function test_pasted_text_is_validated(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $url = self::URL."/{$a->id}/sources/text";

        $this->postJson($url, ['text' => 'too short'], $t['headers'])->assertStatus(422)->assertJsonValidationErrors('text');
        $this->postJson($url, ['text' => ['array']], $t['headers'])->assertStatus(422);
        $this->postJson($url, ['text' => str_repeat('x', 100_001)], $t['headers'])->assertStatus(422);
        $this->postJson($url, ['text' => str_repeat('x', 60), 'label' => str_repeat('l', 121)], $t['headers'])->assertStatus(422);
    }

    public function test_deleting_a_source_or_the_assessment_removes_the_stored_files(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $one = $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->docx(['One'])], $t['headers'])->json('result.id');
        $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->pptx(['Two'])], $t['headers'])->assertCreated();
        $this->assertCount(2, Storage::disk('private')->allFiles());

        $this->deleteJson(self::URL."/{$a->id}/sources/{$one}", [], $t['headers'])->assertOk();
        $this->assertCount(1, Storage::disk('private')->allFiles());

        $this->deleteJson(self::URL."/{$a->id}", [], $t['headers'])->assertOk();
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('ai_assessment_sources', 0);
    }
}
