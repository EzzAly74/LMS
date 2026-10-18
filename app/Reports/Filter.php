<?php

namespace App\Reports;

/**
 * One filter a report accepts. Its validation rules come from here, so the
 * request, the UI and the export header all read one definition.
 *
 *   multi       several ids or values from an option source ({@see ReportOptions})
 *   select      one id from an option source
 *   status      several values from a fixed list (`options`: value => lang key)
 *   date_range  `{key}_from` and `{key}_to`, either may be left out
 *   date        one day (an "as of" date)
 *   number      one number from 0 to 100 (a percentage threshold)
 */
final class Filter
{
    public const MULTI = 'multi';
    public const SELECT = 'select';
    public const STATUS = 'status';
    public const DATE_RANGE = 'date_range';
    public const DATE = 'date';
    public const NUMBER = 'number';

    /** No filter list is longer than this (bounded IN clauses). */
    public const MAX_VALUES = 100;

    /**
     * @param  array<string, string>  $options  status filters: value => lang key
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly ?string $source = null,
        public readonly array $options = [],
        public readonly bool $required = false,
        public readonly ?string $dependsOn = null,
    ) {}

    public static function multi(string $key, string $source, ?string $dependsOn = null): self
    {
        return new self($key, self::MULTI, $source, dependsOn: $dependsOn);
    }

    public static function select(string $key, string $source, bool $required = false): self
    {
        return new self($key, self::SELECT, $source, required: $required);
    }

    /** @param  array<string, string>  $options */
    public static function status(string $key, array $options): self
    {
        return new self($key, self::STATUS, options: $options);
    }

    public static function dateRange(string $key): self
    {
        return new self($key, self::DATE_RANGE);
    }

    public static function date(string $key): self
    {
        return new self($key, self::DATE);
    }

    public static function number(string $key): self
    {
        return new self($key, self::NUMBER);
    }

    /** True when the values are strings (departments), not record ids. */
    public function usesStrings(): bool
    {
        return in_array($this->source, ReportOptions::STRING_SOURCES, true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $required = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            self::MULTI => [
                $this->key => ['nullable', 'array', 'max:'.self::MAX_VALUES],
                "{$this->key}.*" => $this->usesStrings() ? ['string', 'max:255'] : ['integer', 'min:1'],
            ],
            self::SELECT => [$this->key => [$required, 'integer', 'min:1']],
            self::STATUS => [
                $this->key => ['nullable', 'array', 'max:'.count($this->options)],
                "{$this->key}.*" => ['string', 'in:'.implode(',', array_keys($this->options))],
            ],
            self::DATE_RANGE => [
                "{$this->key}_from" => ['nullable', 'date_format:Y-m-d'],
                // The order of the two is checked in ReportRequest::after(): a
                // field-reference rule fails when `_from` is left out.
                "{$this->key}_to" => ['nullable', 'date_format:Y-m-d'],
            ],
            self::DATE => [$this->key => ['nullable', 'date_format:Y-m-d']],
            self::NUMBER => [$this->key => ['nullable', 'numeric', 'min:0', 'max:100']],
        };
    }

    public function label(): string
    {
        return __("reports.filters.{$this->key}");
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'type' => $this->type,
            'source' => $this->source,
            'required' => $this->required,
            'depends_on' => $this->dependsOn,
            'options' => array_map(
                fn (string $value) => ['id' => $value, 'label' => __($this->options[$value])],
                array_keys($this->options),
            ),
        ];
    }
}
