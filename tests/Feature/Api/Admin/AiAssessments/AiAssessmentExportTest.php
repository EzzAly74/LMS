<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\AiAssessment;
use PhpOffice\PhpSpreadsheet\IOFactory as SheetIO;
use ZipArchive;

/** Word / PDF / Excel, trainer and learner, English and Arabic, injection-safe. */
class AiAssessmentExportTest extends AiAssessmentTestCase
{
    private function full(AiAssessment $a): void
    {
        $this->question($a, ['prompt' => '=HYPERLINK("http://evil.test","click") <b>bold</b> & co']);
        $this->question($a, ['type' => 'true_false', 'prompt' => 'Helmets are optional.', 'options' => null, 'answer' => ['value' => false]]);
        $this->question($a, ['type' => 'multi', 'prompt' => 'Pick the PPE.', 'options' => ['Helmet', 'Gloves', 'Sandals'], 'answer' => ['indexes' => [0, 1]]]);
        $this->question($a, ['type' => 'short', 'prompt' => 'Why report near misses?', 'options' => null, 'answer' => ['text' => 'To prevent accidents.']]);
        $this->question($a, ['type' => 'fill_blank', 'prompt' => 'Exits must stay _____.', 'options' => null, 'answer' => ['answers' => ['clear']]]);
        $this->question($a, ['type' => 'matching', 'prompt' => 'Match the sign.', 'options' => [
            ['left' => 'Red', 'right' => 'Stop'], ['left' => 'Green', 'right' => 'Safe'], ['left' => 'Yellow', 'right' => 'Caution'],
        ], 'answer' => null]);
        $this->question($a, ['type' => 'scenario', 'context' => 'A colleague blocks an exit.', 'prompt' => 'What do you do?', 'options' => ['Ignore', 'Ask them to move it'], 'answer' => ['indexes' => [1]]]);
    }

    public function test_excel_has_one_row_per_question_and_never_runs_a_formula(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $this->full($a);

        $response = $this->get(self::URL."/{$a->id}/export?format=xlsx&variant=trainer", $t['headers'])->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Workplace safety basics - Post-Assessment - EN - Trainer version.xlsx', $response->headers->get('Content-Disposition'));

        $sheet = $this->sheet($response->streamedContent());
        $this->assertSame('Correct answer', $sheet->getCell('F1')->getValue());
        $this->assertSame(8, $sheet->getHighestRow());
        $cell = $sheet->getCell('D2');
        $this->assertSame('s', $cell->getDataType(), 'a leading "=" stays text');
        $this->assertStringStartsWith('=HYPERLINK', $cell->getValue());
        $this->assertSame('B) Gloves', explode(', ', $sheet->getCell('F4')->getValue())[1]);
        $this->assertSame('False', $sheet->getCell('F3')->getValue());

        $learner = $this->sheet($this->get(self::URL."/{$a->id}/export?format=xlsx&variant=learner", $t['headers'])->assertOk()->streamedContent());
        $this->assertSame('E', $learner->getHighestColumn(), 'no answer columns for learners');
    }

    public function test_word_trainer_has_the_key_and_learner_has_none(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $this->full($a);

        $trainer = $this->docText($this->get(self::URL."/{$a->id}/export?format=docx&variant=trainer", $t['headers'])->assertOk()->streamedContent());
        $learner = $this->docText($this->get(self::URL."/{$a->id}/export?format=docx&variant=learner", $t['headers'])->assertOk()->streamedContent());

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; co', $trainer, 'escaped, the document stays valid');
        $this->assertStringContainsString('To prevent accidents.', $trainer);
        $this->assertStringNotContainsString('To prevent accidents.', $learner);
        $this->assertStringNotContainsString('Answer:', $learner);
        $this->assertStringContainsString('Name:', $learner);
        $this->assertStringContainsString('A colleague blocks an exit.', $learner);
    }

    public function test_arabic_exports_in_arabic_right_to_left(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model'], ['content_language' => 'ar', 'title' => 'السلامة المهنية']);
        $this->question($a, ['prompt' => 'ما الذي يجب أن يبقى خاليًا؟', 'options' => ['مخارج الطوارئ', 'المكاتب'], 'answer' => ['indexes' => [0]]]);

        $pdf = $this->get(self::URL."/{$a->id}/export?format=pdf&variant=trainer", $t['headers'])->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString("filename*=utf-8''", $pdf->headers->get('Content-Disposition'));

        $xlsx = $this->sheet($this->get(self::URL."/{$a->id}/export?format=xlsx&variant=trainer", $t['headers'])->streamedContent());
        $this->assertTrue($xlsx->getRightToLeft());
        $this->assertSame('الإجابة الصحيحة', $xlsx->getCell('F1')->getValue());
        $this->assertSame('أ) مخارج الطوارئ', $xlsx->getCell('F2')->getValue());

        $doc = $this->docText($this->get(self::URL."/{$a->id}/export?format=docx&variant=learner", $t['headers'])->streamedContent());
        $this->assertStringContainsString('<w:bidi', $doc);
        $this->assertStringContainsString('نسخة المتعلم', $doc);
    }

    public function test_the_matching_column_is_shuffled_the_same_way_every_time(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $this->full($a);

        $first = $this->sheet($this->get(self::URL."/{$a->id}/export?format=xlsx&variant=trainer", $t['headers'])->streamedContent());
        $second = $this->sheet($this->get(self::URL."/{$a->id}/export?format=xlsx&variant=trainer", $t['headers'])->streamedContent());
        $this->assertSame($first->getCell('E7')->getValue(), $second->getCell('E7')->getValue());
        $this->assertMatchesRegularExpression('/^1-[ABC], 2-[ABC], 3-[ABC]$/', $first->getCell('F7')->getValue());
    }

    public function test_export_input_is_validated(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);

        $this->getJson(self::URL."/{$a->id}/export?format=pdf&variant=trainer", $t['headers'])->assertStatus(422)
            ->assertJsonPath('message', __('ai_assessments.errors.no_questions'));
        $this->question($a);
        $this->getJson(self::URL."/{$a->id}/export?format=csv&variant=trainer", $t['headers'])->assertStatus(422)->assertJsonValidationErrors('format');
        $this->getJson(self::URL."/{$a->id}/export?format=pdf&variant=admin", $t['headers'])->assertStatus(422)->assertJsonValidationErrors('variant');
        $this->getJson(self::URL."/{$a->id}/export?format=pdf&variant=learner", $this->trainer(['view-quizzes'])['headers'])->assertStatus(403);
    }

    private function sheet(string $bytes): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'aix').'.xlsx';
        file_put_contents($path, $bytes);

        return SheetIO::load($path)->getActiveSheet();
    }

    private function docText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aid');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'a valid .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse(simplexml_load_string($xml), 'well-formed document XML');

        return $xml;
    }
}
