<?php

namespace App\Reports;

use Closure;

/**
 * One column of a report's on-screen preview (Figma 2201:82041, "Preview:
 * <report>"): a few key columns, worked out from the full row. The export
 * always carries every spec column ("export for the full dataset").
 *
 * A `status` column shows a badge; `tones` maps each value (a lang key) to
 * its tone: success, warning, danger or neutral.
 */
final class PreviewColumn
{
    public const STATUS = 'status';

    /**
     * @param  Closure(array<string, mixed>, ReportContext): mixed  $value
     * @param  array<string, string>  $tones  lang key => tone
     */
    public function __construct(
        public readonly string $key,
        private readonly string $labelKey,
        public readonly string $type,
        private readonly Closure $value,
        public readonly bool $strong = false,
        private readonly array $tones = [],
    ) {}

    /** Show the full row's column as is. */
    public static function of(string $key, string $labelKey, string $type = Column::TEXT, bool $strong = false): self
    {
        return new self($key, $labelKey, $type, fn (array $row) => $row[$key] ?? null, $strong);
    }

    /** @param  array<string, string>  $tones  lang key => tone */
    public static function status(string $key, string $labelKey, array $tones): self
    {
        return new self($key, $labelKey, self::STATUS, fn (array $row) => $row[$key] ?? null, false, $tones);
    }

    /** The learner's name in the reader's language, the other one when missing. */
    public static function learner(): self
    {
        return new self('learner', 'reports.preview.learner', Column::TEXT, fn (array $row, ReportContext $c) => $c->isArabic()
            ? ($row['full_name_ar'] ?? $row['full_name_en'] ?? null)
            : ($row['full_name_en'] ?? $row['full_name_ar'] ?? null), true);
    }

    /** @param  Closure(array<string, mixed>, ReportContext): mixed  $value */
    public static function computed(string $key, string $labelKey, string $type, Closure $value, bool $strong = false): self
    {
        return new self($key, $labelKey, $type, $value, $strong);
    }

    /** @param  array<string, mixed>  $row */
    public function value(array $row, ReportContext $context): mixed
    {
        return ($this->value)($row, $context);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tones = [];
        foreach ($this->tones as $langKey => $tone) {
            $tones[] = ['label' => __($langKey), 'tone' => $tone];
        }

        return [
            'key' => $this->key,
            'label' => __($this->labelKey),
            'type' => $this->type,
            'strong' => $this->strong,
            'tones' => $tones,
        ];
    }
}
