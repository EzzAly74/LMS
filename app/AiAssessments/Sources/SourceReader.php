<?php

namespace App\AiAssessments\Sources;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Identifies an uploaded source by its bytes (never by its name or the
 * browser's MIME type) and pulls the text out of DOCX / PPTX with the page or
 * slide it came from, so generated questions can cite it.
 *
 *  - PDF:  `%PDF-` signature. Not parsed here: it goes to Gemini natively,
 *          which keeps the layout and handles Arabic glyph order far better
 *          than a PHP text extractor (D-113 A-D05).
 *  - DOCX: a ZIP holding word/document.xml.  Text per page, split at explicit
 *          and Word-rendered page breaks: "[Page n]".
 *  - PPTX: a ZIP holding ppt/presentation.xml. Text per slide, in slide
 *          order, with speaker notes: "[Slide n]".
 *
 * Defensive reading: XML parts larger than `limits.zip_entry_bytes` are
 * refused (zip bombs), and XML is parsed with LIBXML_NONET and no entity
 * substitution (XXE).
 */
final class SourceReader
{
    /** @return 'pdf'|'docx'|'pptx'|null */
    public function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }

        if (! str_starts_with($head, "PK\x03\x04")) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('word/document.xml') !== false) {
                return 'docx';
            }
            if ($zip->locateName('ppt/presentation.xml') !== false) {
                return 'pptx';
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{text:string, units:int}
     *
     * @throws SourceReadException
     */
    public function extract(string $path, string $kind): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SourceReadException('unreadable');
        }

        try {
            return $kind === 'docx' ? $this->docx($zip) : $this->pptx($zip);
        } finally {
            $zip->close();
        }
    }

    /** @return array{text:string, units:int} */
    private function docx(ZipArchive $zip): array
    {
        $xpath = $this->xpath($zip, 'word/document.xml');
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $pages = [''];
        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            /** @var DOMElement $paragraph */
            $line = '';
            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:lastRenderedPageBreak', $paragraph) as $node) {
                /** @var DOMElement $node */
                if ($node->localName === 't') {
                    $line .= $node->textContent;
                } elseif ($node->localName === 'tab') {
                    $line .= "\t";
                } elseif ($node->localName === 'br' && $node->getAttribute('w:type') !== 'page') {
                    $line .= "\n";
                } else {
                    // A page break: whatever follows is on the next page.
                    $pages[count($pages) - 1] .= $line;
                    $line = '';
                    $pages[] = '';
                }
            }
            $pages[count($pages) - 1] .= trim($line) === '' ? '' : $line."\n";
        }

        return $this->numbered($pages, 'Page');
    }

    /** @return array{text:string, units:int} */
    private function pptx(ZipArchive $zip): array
    {
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = $name;
            }
        }
        ksort($slides);

        $texts = [];
        foreach ($slides as $number => $name) {
            $text = $this->drawingText($this->xpath($zip, $name));
            $notes = "ppt/notesSlides/notesSlide{$number}.xml";
            if ($zip->locateName($notes) !== false) {
                $noteText = trim($this->drawingText($this->xpath($zip, $notes)));
                if ($noteText !== '') {
                    $text .= "Notes: ".$noteText."\n";
                }
            }
            $texts[] = $text;
        }

        return $this->numbered($texts, 'Slide');
    }

    private function drawingText(DOMXPath $xpath): string
    {
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $text = '';
        foreach ($xpath->query('//a:p') as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//a:t', $paragraph) as $run) {
                $line .= $run->textContent;
            }
            if (trim($line) !== '') {
                $text .= $line."\n";
            }
        }

        return $text;
    }

    /**
     * @param  list<string>  $units
     * @return array{text:string, units:int}
     */
    private function numbered(array $units, string $label): array
    {
        $out = '';
        foreach ($units as $i => $text) {
            $text = trim(preg_replace("/[ \t]+/u", ' ', $text) ?? '');
            if ($text !== '') {
                $out .= '['.$label.' '.($i + 1)."]\n".$text."\n\n";
            }
        }

        return ['text' => trim($out), 'units' => count($units)];
    }

    private function xpath(ZipArchive $zip, string $entry): DOMXPath
    {
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > (int) config('ai-assessments.limits.zip_entry_bytes')) {
            throw new SourceReadException('too_large');
        }

        $xml = $zip->getFromName($entry);
        if ($xml === false) {
            throw new SourceReadException('unreadable');
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new SourceReadException('unreadable');
        }

        return new DOMXPath($dom);
    }
}
