<?php

namespace App\Reports\Reports;

use App\Reports\PreviewColumn;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Support\LocalizedJson;
use App\Support\PersonName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 9. External Training: one row per external training request (D-112).
 *
 * A request carries one hours value, approved as claimed: Hours Approved is
 * that value once the request is approved, blank otherwise. The proof file
 * is required on every request, so Attachment reads Yes whenever one is
 * stored. Requests have no training type, so Training Type is left out.
 */
final class ExternalTrainingReport extends QueryReport
{
    public const STATUSES = [
        'pending' => 'reports.values.external.pending',
        'approved' => 'reports.values.external.approved',
        'rejected' => 'reports.values.external.rejected',
        'withdrawn' => 'reports.values.external.withdrawn',
    ];

    public function key(): string
    {
        return 'external-training';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('claim_id', 'claim'),
            new Column('training_title', 'training'),
            new Column('provider', 'training'),
            new Column('training_start_date', 'training', Column::DATE),
            new Column('training_end_date', 'training', Column::DATE),
            new Column('hours_claimed', 'hours', Column::NUMBER),
            new Column('hours_approved', 'hours', Column::NUMBER),
            new Column('cost', 'cost', Column::NUMBER),
            new Column('currency', 'cost'),
            new Column('linked_qualification', 'claim'),
            new Column('attachment', 'claim', Column::YES_NO),
            new Column('submission_date', 'claim', Column::DATE),
            new Column('claim_status', 'approval'),
            new Column('approver', 'approval'),
            new Column('decision_date', 'approval', Column::DATE),
            new Column('rejection_reason', 'approval'),
        ];
    }

    /** Figma 2201:82041: Learner, Provider, Title, Hours, Qualification, Status, Submitted, Approved. */
    public function preview(ReportContext $context): array
    {
        return [
            PreviewColumn::learner(),
            PreviewColumn::of('provider', 'reports.preview.provider'),
            PreviewColumn::of('training_title', 'reports.preview.title'),
            PreviewColumn::of('hours_claimed', 'reports.preview.hours', Column::NUMBER, true),
            PreviewColumn::of('linked_qualification', 'reports.preview.qualification'),
            PreviewColumn::status('claim_status', 'reports.preview.status', [
                self::STATUSES['approved'] => 'success',
                self::STATUSES['pending'] => 'warning',
                self::STATUSES['rejected'] => 'danger',
                self::STATUSES['withdrawn'] => 'neutral',
            ]),
            PreviewColumn::of('submission_date', 'reports.preview.submitted', Column::DATE),
            PreviewColumn::computed('approved_on', 'reports.preview.approved', Column::DATE,
                fn (array $r) => $r['hours_approved'] !== null ? $r['decision_date'] : null),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::dateRange('training'),
            Filter::multi('qualification_ids', 'qualifications'),
            Filter::multi('providers', 'providers'),
            Filter::multi('employee_ids', 'employees'),
            Filter::multi('org_units', 'org_units'),
            Filter::status('statuses', self::STATUSES),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $from = $context->from('training');
        $to = $context->to('training');

        return DB::table('external_training_requests as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('qualification_skills as q', 'q.id', '=', 'e.qualification_skill_id')
            ->leftJoin('admins as d', 'd.id', '=', 'e.decided_by')
            ->select([
                'e.id as claim_id', 'e.title', 'e.provider', 'e.start_date', 'e.end_date', 'e.hours', 'e.cost',
                'e.currency', 'e.certificate_path', 'e.created_at as submitted_at', 'e.status', 'e.decided_at',
                'e.rejection_reason', 'q.name as qualification_name',
            ])
            ->selectRaw(PersonName::adminSql('d', $context->locale).' AS approver_name')
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::learners($q, $context, 'e.user_id'))
            ->when($context->strings('providers'), fn ($q, $v) => $q->whereIn('e.provider', $v))
            ->when($context->ids('qualification_ids'), fn ($q, $v) => $q->whereIn('e.qualification_skill_id', $v))
            ->when($context->strings('statuses'), fn ($q, $v) => $q->whereIn('e.status', $v))
            // A training that ran at any time in the period.
            ->when($to, fn ($q) => $q->whereDate('e.start_date', '<=', $to->toDateString()))
            ->when($from, fn ($q) => $q->whereDate('e.end_date', '>=', $from->toDateString()))
            ->orderByDesc('e.created_at')
            ->orderByDesc('e.id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($context, $keys) {
            $status = array_key_exists($row->status, self::STATUSES) ? __(self::STATUSES[$row->status]) : Employee::text($row->status);
            $decided = in_array($row->status, ['approved', 'rejected'], true);

            return self::shape([
                ...Employee::values($row, $context),
                'claim_id' => (string) $row->claim_id,
                'training_title' => Employee::text($row->title),
                'provider' => Employee::text($row->provider),
                'training_start_date' => Employee::date($row->start_date),
                'training_end_date' => Employee::date($row->end_date),
                'hours_claimed' => $row->hours === null ? null : (float) $row->hours,
                'hours_approved' => $row->status === 'approved' && $row->hours !== null ? (float) $row->hours : null,
                'cost' => $row->cost === null ? null : (float) $row->cost,
                'currency' => $row->cost === null ? null : Employee::text($row->currency),
                'linked_qualification' => LocalizedJson::pick($row->qualification_name, $context->locale),
                'attachment' => Employee::text($row->certificate_path) !== null,
                'submission_date' => Employee::date($row->submitted_at),
                'claim_status' => $status,
                'approver' => $decided ? Employee::text($row->approver_name) : null,
                'decision_date' => $decided ? Employee::date($row->decided_at) : null,
                'rejection_reason' => $row->status === 'rejected' ? Employee::text($row->rejection_reason) : null,
            ], $keys);
        })->values()->all();
    }
}
