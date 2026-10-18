<?php

namespace App\AiAssessments\Export;

use App\Models\AiAssessment;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as WordSettings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Word / PDF / Excel exports (D-113), trainer (with the key) or learner
 * (without). All built in memory-bounded steps (an assessment holds at most
 * `limits.questions_total` questions) and streamed.
 *
 * Injection safety:
 *  - PDF is rendered from HTML with every value escaped (e()).
 *  - Word: PhpWord output escaping is switched on, so "<" or "&" in a
 *    question cannot corrupt the document XML.
 *  - Excel: every cell is written as an explicit string, so a value such as
 *    "=HYPERLINK(...)" is shown as text, never run as a formula (CSV/formula
 *    injection).
 *
 * Arabic: mPDF shapes Arabic glyphs (the D-050 risk 1 that ruled out
 * @react-pdf); the Word paragraphs carry bidi + rtl; the sheet is right-to-left.
 */
final class AssessmentExporter
{
    public const FORMATS = ['docx', 'pdf', 'xlsx'];
    public const VARIANTS = ['trainer', 'learner'];

    public function export(AiAssessment $assessment, string $format, string $variant): Response
    {
        $assessment->loadMissing(['questions', 'course:id,title']);
        $doc = new ExportDocument($assessment, $variant === 'trainer');
        $filename = $this->filename($assessment, $variant, $format);

        return match ($format) {
            'docx' => $this->docx($doc, $assessment->content_language, $filename),
            'pdf'  => $this->pdf($doc, $assessment->content_language, $filename),
            'xlsx' => $this->xlsx($doc, $assessment->content_language, $filename),
        };
    }

    /** "<title> - <type> - <LANG> - <variant>.<ext>", reduced to safe characters (Arabic kept). */
    public function filename(AiAssessment $assessment, string $variant, string $format): string
    {
        $lang = $assessment->content_language;
        $parts = [
            $assessment->title,
            trans('ai_assessments.export.types.'.$assessment->type, [], $lang),
            strtoupper($lang),
            trans('ai_assessments.export.variants.'.$variant, [], $lang),
        ];
        $name = implode(' - ', array_map(
            fn ($p) => trim((string) preg_replace('/[^\p{L}\p{N} _().-]+/u', '', (string) $p)),
            $parts,
        ));

        return mb_substr($name, 0, 150).'.'.$format;
    }

    private function docx(ExportDocument $doc, string $lang, string $filename): StreamedResponse
    {
        WordSettings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        if ($doc->rtl) {
            $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('en-US', null, 'ar-EG'));
        }
        $font = ['name' => $doc->rtl ? 'Arial' : 'Calibri', 'size' => 11, 'rtl' => $doc->rtl];
        $para = ['bidi' => $doc->rtl, 'alignment' => $doc->rtl ? 'right' : 'left', 'spaceAfter' => 80];
        $muted = $font + ['color' => '555555', 'size' => 10];

        $section = $word->addSection();
        $section->addText($doc->title, $font + ['bold' => true, 'size' => 16], $para);
        $section->addText($doc->subtitle, $muted, $para);
        if ($doc->course) {
            $section->addText($doc->label('course', $lang).': '.$doc->course, $muted, $para);
        }
        if (! $doc->withAnswers) {
            $section->addText($doc->label('name', $lang).': ________________________    '.$doc->label('date', $lang).': ____________', $font, $para + ['spaceBefore' => 160]);
        }

        foreach ($doc->items as $item) {
            $section->addTextBreak(1);
            if ($item['context']) {
                $section->addText($item['context'], $font + ['italic' => true], $para);
            }
            $section->addText($item['number'].'. '.$item['prompt'], $font + ['bold' => true], $para);
            if ($item['hint']) {
                $section->addText($item['hint'], $muted, $para);
            }
            foreach ($item['options'] as [$letter, $text]) {
                $section->addText('    '.$letter.') '.$text, $font, $para);
            }
            if ($item['pairs']) {
                $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'BBBBBB', 'cellMargin' => 60, 'bidiVisual' => $doc->rtl]);
                $rows = max(count($item['pairs']['left']), count($item['pairs']['right']));
                for ($r = 0; $r < $rows; $r++) {
                    $table->addRow();
                    $left = $item['pairs']['left'][$r] ?? null;
                    $right = $item['pairs']['right'][$r] ?? null;
                    $table->addCell(4500)->addText($left ? $left[0].'. '.$left[1].'   ____' : '', $font, $para);
                    $table->addCell(4500)->addText($right ? $right[0].') '.$right[1] : '', $font, $para);
                }
            }
            for ($l = 0; $l < $item['lines'] && ! $doc->withAnswers; $l++) {
                $section->addText(str_repeat('_', 70), $muted, $para);
            }
            if ($doc->withAnswers) {
                $this->wordKey($section, $doc, $item, $lang, $font, $muted, $para);
            }
        }

        return response()->streamDownload(function () use ($word) {
            IOFactory::createWriter($word, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function wordKey($section, ExportDocument $doc, array $item, string $lang, array $font, array $muted, array $para): void
    {
        $section->addText($doc->label('answer', $lang).': '.$item['answer'], $font + ['bold' => true, 'color' => '1B6E3A'], $para);
        if ($item['explanation']) {
            $section->addText($doc->label('explanation', $lang).': '.$item['explanation'], $muted, $para);
        }
        $meta = array_filter([
            $item['source'] ? $doc->label('source', $lang).': '.$item['source'] : null,
            $item['bloom'] ? $doc->label('bloom', $lang).': '.$item['bloom'] : null,
            $item['difficulty'] ? $doc->label('difficulty', $lang).': '.$item['difficulty'] : null,
        ]);
        if ($meta) {
            $section->addText(implode('  ·  ', $meta), $muted, $para);
        }
    }

    private function pdf(ExportDocument $doc, string $lang, string $filename): Response
    {
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'tempDir'          => $tmp,
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'margin_top'       => 16,
            'margin_bottom'    => 16,
        ]);
        $mpdf->SetDirectionality($doc->rtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle($doc->title);
        // No remote fetches while rendering: the HTML is ours and holds no URLs.
        $mpdf->showImageErrors = false;

        $mpdf->WriteHTML($this->html($doc, $lang));

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => $this->disposition($filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function html(ExportDocument $doc, string $lang): string
    {
        $align = $doc->rtl ? 'right' : 'left';
        $h = '<style>
            body { font-family: dejavusans; font-size: 11pt; color: #1f2933; }
            h1 { font-size: 17pt; margin: 0 0 4px; }
            .muted { color: #5f6b76; font-size: 9.5pt; }
            .q { margin-top: 14px; page-break-inside: avoid; }
            .prompt { font-weight: bold; }
            .context { font-style: italic; margin-bottom: 4px; }
            .opt { margin-'.($doc->rtl ? 'right' : 'left').': 18px; }
            .key { color: #1b6e3a; font-weight: bold; margin-top: 4px; }
            .line { border-bottom: 1px solid #9aa5b1; height: 18px; }
            table.match { border-collapse: collapse; width: 100%; margin-top: 4px; }
            table.match td { border: 1px solid #c8d0d8; padding: 4px 6px; width: 50%; text-align: '.$align.'; }
        </style>';

        $h .= '<h1>'.e($doc->title).'</h1><div class="muted">'.e($doc->subtitle).'</div>';
        if ($doc->course) {
            $h .= '<div class="muted">'.e($doc->label('course', $lang).': '.$doc->course).'</div>';
        }
        if (! $doc->withAnswers) {
            $h .= '<p>'.e($doc->label('name', $lang)).': ______________________ &nbsp; '.e($doc->label('date', $lang)).': ____________</p>';
        }

        foreach ($doc->items as $item) {
            $h .= '<div class="q">';
            if ($item['context']) {
                $h .= '<div class="context">'.nl2br(e($item['context'])).'</div>';
            }
            $h .= '<div class="prompt">'.e($item['number'].'. '.$item['prompt']).'</div>';
            if ($item['hint']) {
                $h .= '<div class="muted">'.e($item['hint']).'</div>';
            }
            foreach ($item['options'] as [$letter, $text]) {
                $h .= '<div class="opt">'.e($letter.') '.$text).'</div>';
            }
            if ($item['pairs']) {
                $h .= '<table class="match">';
                $rows = max(count($item['pairs']['left']), count($item['pairs']['right']));
                for ($r = 0; $r < $rows; $r++) {
                    $left = $item['pairs']['left'][$r] ?? null;
                    $right = $item['pairs']['right'][$r] ?? null;
                    $h .= '<tr><td>'.($left ? e($left[0].'. '.$left[1]).' ____' : '').'</td><td>'.($right ? e($right[0].') '.$right[1]) : '').'</td></tr>';
                }
                $h .= '</table>';
            }
            for ($l = 0; $l < $item['lines'] && ! $doc->withAnswers; $l++) {
                $h .= '<div class="line"></div>';
            }
            if ($doc->withAnswers) {
                $h .= '<div class="key">'.e($doc->label('answer', $lang).': '.$item['answer']).'</div>';
                if ($item['explanation']) {
                    $h .= '<div class="muted">'.e($doc->label('explanation', $lang).': '.$item['explanation']).'</div>';
                }
                $meta = array_filter([
                    $item['source'] ? $doc->label('source', $lang).': '.$item['source'] : null,
                    $item['bloom'] ? $doc->label('bloom', $lang).': '.$item['bloom'] : null,
                    $item['difficulty'] ? $doc->label('difficulty', $lang).': '.$item['difficulty'] : null,
                ]);
                if ($meta) {
                    $h .= '<div class="muted">'.e(implode('  ·  ', $meta)).'</div>';
                }
            }
            $h .= '</div>';
        }

        return $h;
    }

    private function xlsx(ExportDocument $doc, string $lang, string $filename): StreamedResponse
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]+/u', '', $doc->title) ?: 'Assessment', 0, 31));
        $sheet->setRightToLeft($doc->rtl);

        $columns = ['number', 'type', 'context', 'question', 'options'];
        if ($doc->withAnswers) {
            array_push($columns, 'correct', 'explanation', 'source', 'bloom', 'difficulty', 'status');
        }

        foreach ($columns as $c => $key) {
            $sheet->setCellValueExplicit([$c + 1, 1], $doc->label('columns.'.$key, $lang), DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, 1, count($columns), 1])->getFont()->setBold(true);

        foreach ($doc->items as $r => $item) {
            $options = array_map(fn ($o) => $o[0].') '.$o[1], $item['options']);
            if ($item['pairs']) {
                $options = array_map(
                    fn ($l, $rt) => ($l ? $l[0].'. '.$l[1] : '').'  |  '.($rt ? $rt[0].') '.$rt[1] : ''),
                    $item['pairs']['left'], $item['pairs']['right'],
                );
            }
            $values = [
                'number'      => (string) $item['number'],
                'type'        => $item['type_label'],
                'context'     => (string) $item['context'],
                'question'    => $item['prompt'],
                'options'     => implode("\n", $options),
                'correct'     => $item['answer'],
                'explanation' => (string) $item['explanation'],
                'source'      => (string) $item['source'],
                'bloom'       => (string) $item['bloom'],
                'difficulty'  => (string) $item['difficulty'],
                'status'      => $item['status'],
            ];
            foreach ($columns as $c => $key) {
                $sheet->setCellValueExplicit([$c + 1, $r + 2], $values[$key], DataType::TYPE_STRING);
            }
        }

        $last = count($doc->items) + 1;
        $sheet->getStyle([1, 1, count($columns), $last])->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        foreach ($columns as $c => $key) {
            $sheet->getColumnDimensionByColumn($c + 1)->setWidth(match ($key) {
                'number' => 5, 'type', 'bloom', 'difficulty', 'status' => 16, 'question', 'context', 'options', 'explanation' => 48, default => 28,
            });
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function disposition(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9 _().-]+/', '', $filename) ?: 'assessment';

        return \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $filename, $fallback === '.pdf' ? 'assessment.pdf' : $fallback);
    }
}
