<?php

namespace App\Exports;

use App\Exports\Sheets\CohortScheduleModulesSheet;
use App\Exports\Sheets\CohortScheduleSessionsSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * "Download Schedule Template" in the New / Edit Cohort modal (Figma 2393:123167).
 *
 * Sheet 1 (read back by CohortScheduleImportService): one pre-numbered row per
 * planned session with date (YYYY-MM-DD), start_time and end_time (24-hour
 * HH:MM), an optional location and an optional content cell: the IDs of the
 * course modules the session covers, separated by commas (D-079).
 *
 * Sheet 2 lists the course's modules (ID, English and Arabic title) so the
 * admin can pick the IDs; it is reference only and is not read on upload.
 *
 * When editing a cohort, sheet 1 starts with its sessions as they are (their
 * content included), then blank numbered rows for the sessions still to plan
 * (at least EXTRA_ROWS).
 */
class CohortScheduleTemplateExport implements WithMultipleSheets
{
    use Exportable;

    /** Rows offered when the course has no planned session count. */
    public const DEFAULT_ROWS = 10;

    /** Blank rows after an edited cohort's sessions when its plan is already met. */
    public const EXTRA_ROWS = 5;

    /**
     * @param  list<array{date: string, from: ?string, to: ?string, location: ?string, content: list<int>}>  $existing
     * @param  list<array{id: int, en: string, ar: string}>  $modules
     */
    public function __construct(
        private readonly int $sessions,
        private readonly array $existing = [],
        private readonly array $modules = [],
    ) {}

    public function sheets(): array
    {
        return [
            new CohortScheduleSessionsSheet($this->sessions, $this->existing),
            new CohortScheduleModulesSheet($this->modules),
        ];
    }
}
