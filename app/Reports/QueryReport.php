<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * A report with one database row per report row. The query carries the
 * filters and a stable order; map() turns a page (or an export chunk) of raw
 * rows into report rows, loading anything extra for the whole batch at once,
 * so the query count does not grow with the rows.
 */
abstract class QueryReport extends Report
{
    abstract protected function query(ReportContext $context): Builder;

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    abstract protected function map(Collection $rows, ReportContext $context): array;

    public function page(ReportContext $context, int $page, int $perPage): array
    {
        $query = $this->query($context);
        $total = $this->countOf($query);
        $rows = $total === 0 ? collect() : (clone $query)->forPage($page, $perPage)->get();

        return ['rows' => $this->map($rows, $context), 'total' => $total];
    }

    public function chunks(ReportContext $context, int $size): iterable
    {
        $query = $this->query($context);
        for ($page = 1; ; $page++) {
            $rows = (clone $query)->forPage($page, $size)->get();
            if ($rows->isEmpty()) {
                return;
            }
            yield $this->map($rows, $context);
            if ($rows->count() < $size) {
                return;
            }
        }
    }

    public function count(ReportContext $context): int
    {
        return $this->countOf($this->query($context));
    }

    /** Counts a grouped query correctly too (as a subquery). */
    private function countOf(Builder $query): int
    {
        $base = (clone $query);
        $base->orders = null;

        if ($base->groups !== null) {
            return (int) \Illuminate\Support\Facades\DB::query()->fromSub($base, 'report_count')->count();
        }

        $base->columns = null;

        return (int) $base->count();
    }
}
