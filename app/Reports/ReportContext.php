<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * The validated filters and the reader of one report request: the same
 * object drives the on-screen page and the export, so both show the same rows.
 */
final class ReportContext
{
    /**
     * @param  array<string, mixed>  $filters  validated filter values only
     * @param  list<int>|null  $courseIds      the reader's course scope (D-074), null = every course
     */
    public function __construct(
        public readonly array $filters,
        public readonly string $locale,
        public readonly ?array $courseIds,
        public readonly ?string $search = null,
    ) {}

    public function isArabic(): bool
    {
        return $this->locale === 'ar';
    }

    /** @return list<int> */
    public function ids(string $key): array
    {
        $values = $this->filters[$key] ?? [];
        $values = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_map('intval', array_filter($values, fn ($v) => $v !== null && $v !== ''))));
    }

    public function id(string $key): ?int
    {
        $value = $this->filters[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $values = $this->filters[$key] ?? [];
        $values = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_map('strval', array_filter($values, fn ($v) => $v !== null && $v !== ''))));
    }

    public function number(string $key): ?float
    {
        $value = $this->filters[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** A single-day filter, as the end of that day. */
    public function day(string $key): ?Carbon
    {
        $value = $this->filters[$key] ?? null;

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    public function from(string $key): ?Carbon
    {
        $value = $this->filters["{$key}_from"] ?? null;

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    public function to(string $key): ?Carbon
    {
        $value = $this->filters["{$key}_to"] ?? null;

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    /** Course ids allowed by the scope and the course filter together; null = no limit. */
    public function courseLimit(string $filterKey = 'course_ids'): ?array
    {
        $chosen = $this->ids($filterKey);
        if ($this->courseIds === null) {
            return $chosen === [] ? null : $chosen;
        }

        return $chosen === [] ? $this->courseIds : array_values(array_intersect($chosen, $this->courseIds));
    }
}
