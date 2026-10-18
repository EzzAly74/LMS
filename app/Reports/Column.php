<?php

namespace App\Reports;

/**
 * One column of a report, in the order the NAS spec lists it (D-112). A spec
 * field the system does not capture is left out of the report (human,
 * 2026-10-18: "remove non-existing columns").
 */
final class Column
{
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const PERCENT = 'percent';
    public const DATE = 'date';
    public const DATETIME = 'datetime';
    public const YES_NO = 'yes_no';

    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $type = self::TEXT,
        private readonly ?string $label = null,
    ) {}

    /** A column named at runtime (an evaluation question). */
    public static function named(string $key, string $group, string $type, string $label): self
    {
        return new self($key, $group, $type, $label);
    }

    public function label(): string
    {
        return $this->label ?? __("reports.columns.{$this->key}");
    }

    public function groupLabel(): string
    {
        return __("reports.groups.{$this->group}");
    }

    /** @return array{key:string, label:string, group:string, group_label:string, type:string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'group' => $this->group,
            'group_label' => $this->groupLabel(),
            'type' => $this->type,
        ];
    }
}
