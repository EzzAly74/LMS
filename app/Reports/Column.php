<?php

namespace App\Reports;

/**
 * One column of a report, in the order the NAS spec lists it (D-112).
 *
 * `available` is false for a field the system does not capture yet: the
 * column keeps its place in the file so the layout never changes when the
 * data arrives, its cells stay empty, and the export header and the UI name
 * it as "not captured yet" (human, 2026-10-18).
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
        public readonly bool $available = true,
        private readonly ?string $label = null,
    ) {}

    /** A column the system does not capture yet. */
    public static function missing(string $key, string $group, string $type = self::TEXT): self
    {
        return new self($key, $group, $type, false);
    }

    /** A column named at runtime (an evaluation question). */
    public static function named(string $key, string $group, string $type, string $label): self
    {
        return new self($key, $group, $type, true, $label);
    }

    public function label(): string
    {
        return $this->label ?? __("reports.columns.{$this->key}");
    }

    public function groupLabel(): string
    {
        return __("reports.groups.{$this->group}");
    }

    /** @return array{key:string, label:string, group:string, group_label:string, type:string, available:bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'group' => $this->group,
            'group_label' => $this->groupLabel(),
            'type' => $this->type,
            'available' => $this->available,
        ];
    }
}
