<?php

namespace App\Reports;

/**
 * A report whose rows are aggregates worked out in PHP from shared rules
 * (one row per job title, or per course x cohort x instructor). Their number
 * is bounded by the organisation's job titles or cohorts, not by learners,
 * so the whole set is built once per request and paged in memory.
 */
abstract class ComputedReport extends Report
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    /** @return list<array<string, mixed>> */
    abstract protected function build(ReportContext $context): array;

    public function page(ReportContext $context, int $page, int $perPage): array
    {
        $rows = $this->all($context);

        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows)];
    }

    public function chunks(ReportContext $context, int $size): iterable
    {
        foreach (array_chunk($this->all($context), max(1, $size)) as $chunk) {
            yield $chunk;
        }
    }

    public function count(ReportContext $context): int
    {
        return count($this->all($context));
    }

    /** @return list<array<string, mixed>> */
    private function all(ReportContext $context): array
    {
        $key = md5(serialize([$context->filters, $context->locale, $context->courseIds, $context->search]));

        return $this->memo[$key] ??= $this->build($context);
    }
}
