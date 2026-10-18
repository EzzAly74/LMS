<?php

namespace App\Reports;

use App\Models\Admin;

/**
 * One NAS report (D-112): its columns in the spec's order, its filters, and
 * its rows. Rows are arrays keyed by column key holding plain values: numbers,
 * Y-m-d / Y-m-d H:i strings, booleans for Yes/No, localized text, or null.
 */
abstract class Report
{
    abstract public function key(): string;

    /** @return list<Column> */
    abstract public function columns(ReportContext $context): array;

    /** @return list<Filter> */
    abstract public function filters(): array;

    /**
     * The on-screen preview's columns (Figma 2201:82041); the export keeps
     * every column.
     *
     * @return list<PreviewColumn>
     */
    abstract public function preview(ReportContext $context): array;

    /**
     * Rows as the preview shows them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function previewRows(array $rows, ReportContext $context): array
    {
        $columns = $this->preview($context);

        return array_map(function (array $row) use ($columns, $context) {
            $out = [];
            foreach ($columns as $column) {
                $out[$column->key] = $column->value($row, $context);
            }

            return $out;
        }, $rows);
    }

    /**
     * One page of rows and the total.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    abstract public function page(ReportContext $context, int $page, int $perPage): array;

    /** Every row, in chunks, for the export. @return iterable<list<array<string, mixed>>> */
    abstract public function chunks(ReportContext $context, int $size): iterable;

    abstract public function count(ReportContext $context): int;

    /** May this account open the report? Every report needs view-reports (the route). */
    public function allows(Admin $admin): bool
    {
        return true;
    }

    public function name(): string
    {
        return __("reports.names.{$this->key()}");
    }

    public function description(): string
    {
        return __("reports.descriptions.{$this->key()}");
    }

    /** The report page's subtitle (Figma 2201:82041); the card's description when it has none. */
    public function pageDescription(): string
    {
        $key = "reports.page_descriptions.{$this->key()}";

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $this->description();
    }

    public function rowUnit(): string
    {
        return __("reports.row_units.{$this->key()}");
    }

    /** @return list<string> */
    public function columnKeys(ReportContext $context): array
    {
        return array_map(fn (Column $c) => $c->key, $this->columns($context));
    }

    /**
     * Fill every column key in column order (null when a row has no value).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected static function shape(array $values, array $keys): array
    {
        $row = [];
        foreach ($keys as $key) {
            $row[$key] = $values[$key] ?? null;
        }

        return $row;
    }

    protected static function percent(float|int|null $part, float|int|null $whole): ?float
    {
        if ($part === null || $whole === null || (float) $whole <= 0) {
            return null;
        }

        return round(((float) $part / (float) $whole) * 100, 1);
    }
}
