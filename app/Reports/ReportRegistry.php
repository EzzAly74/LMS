<?php

namespace App\Reports;

use App\Reports\Reports\AssignmentScoresReport;
use App\Reports\Reports\AttendanceReport;
use App\Reports\Reports\CertificateStatusReport;
use App\Reports\Reports\ComplianceByJobTitleReport;
use App\Reports\Reports\CompletionReport;
use App\Reports\Reports\EvaluationResponsesReport;
use App\Reports\Reports\EvaluationSummaryReport;
use App\Reports\Reports\ExternalTrainingReport;
use App\Reports\Reports\IndividualComplianceReport;
use App\Reports\Reports\QuizScoresReport;

/**
 * The NAS reports, in the order of the spec's Overview tab (D-112).
 */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        ComplianceByJobTitleReport::class,
        IndividualComplianceReport::class,
        AttendanceReport::class,
        CompletionReport::class,
        QuizScoresReport::class,
        AssignmentScoresReport::class,
        CertificateStatusReport::class,
        EvaluationSummaryReport::class,
        EvaluationResponsesReport::class,
        ExternalTrainingReport::class,
    ];

    /** @return list<Report> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public function find(string $key): ?Report
    {
        foreach ($this->all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (Report $r) => $r->key(), $this->all());
    }
}
