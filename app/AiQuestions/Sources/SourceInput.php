<?php

namespace App\AiQuestions\Sources;

/**
 * One piece of course material for a generation: extracted text (DOCX, PPTX,
 * pasted) or a PDF on the private disk, sent to the provider as a file.
 */
final class SourceInput
{
    private function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $text = null,
        public readonly ?string $path = null,
    ) {}

    public static function text(string $name, string $text, string $kind = 'text'): self
    {
        return new self($kind, $name, $text);
    }

    public static function pdf(string $name, string $path): self
    {
        return new self('pdf', $name, null, $path);
    }

    public function isPdf(): bool
    {
        return $this->kind === 'pdf';
    }
}
