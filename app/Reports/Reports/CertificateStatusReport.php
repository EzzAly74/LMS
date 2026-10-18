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
 * 7. Certificate Status: one row per certificate, active and revoked (D-112).
 *
 * Certificates do not expire (human 2026-10-18), so Expiry Date, Days to
 * Expiry and "Expiring Soon" are dropped and Status is Valid or Revoked.
 * Certificate Name is the title printed on it (the issue-time snapshot);
 * Linked Course is the course that awarded it (certificates are per course).
 * Issued By is the admin who issued it, or "Issued automatically" when the
 * certificate rule issued it. No public verification page exists, so the
 * Verification Link is left out.
 */
final class CertificateStatusReport extends QueryReport
{
    public const STATUSES = [
        'valid' => 'reports.values.certificate.valid',
        'revoked' => 'reports.values.certificate.revoked',
    ];

    public function key(): string
    {
        return 'certificate-status';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('certificate_no', 'certificate'),
            new Column('certificate_name', 'certificate'),
            new Column('linked_course', 'certificate'),
            new Column('issue_date', 'dates', Column::DATE),
            new Column('certificate_status', 'status'),
            new Column('issued_by', 'status'),
        ];
    }

    public function preview(ReportContext $context): array
    {
        return [
            PreviewColumn::learner(),
            PreviewColumn::of('certificate_no', 'reports.columns.certificate_no', Column::TEXT, true),
            PreviewColumn::of('certificate_name', 'reports.preview.certificate'),
            PreviewColumn::of('issue_date', 'reports.columns.issue_date', Column::DATE),
            PreviewColumn::status('certificate_status', 'reports.preview.status', [
                self::STATUSES['valid'] => 'success',
                self::STATUSES['revoked'] => 'danger',
            ]),
            PreviewColumn::of('issued_by', 'reports.columns.issued_by'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('departments', 'departments'),
            Filter::multi('job_title_ids', 'job_titles'),
            Filter::multi('course_ids', 'courses'),
            Filter::status('statuses', self::STATUSES),
            Filter::dateRange('issued'),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $issuedAt = 'COALESCE(uc.issued_at, uc.created_at)';
        $statuses = $context->strings('statuses');

        return DB::table('user_certificates as uc')
            ->join('users as u', 'u.id', '=', 'uc.user_id')
            ->leftJoin('courses as c', 'c.id', '=', 'uc.course_id')
            ->leftJoin('admins as ib', 'ib.id', '=', 'uc.issued_by')
            ->select([
                'uc.certificate_number', 'uc.status as certificate_state', 'uc.metadata', 'uc.issued_by',
                'c.title as course_title', 'c.title_for_certificate',
            ])
            ->selectRaw("{$issuedAt} AS issued_on")
            ->selectRaw(PersonName::adminSql('ib', $context->locale).' AS issuer_name')
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::courses($q, $context, 'uc.course_id'))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('uc.course_id', $v))
            ->when(count($statuses) === 1, fn ($q) => $q->where('uc.status', $statuses[0] === 'revoked' ? 'revoked' : 'active'))
            ->when($context->from('issued'), fn ($q, $d) => $q->whereRaw("{$issuedAt} >= ?", [$d]))
            ->when($context->to('issued'), fn ($q, $d) => $q->whereRaw("{$issuedAt} <= ?", [$d]))
            ->orderByRaw("{$issuedAt} DESC")
            ->orderByDesc('uc.id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($context, $keys) {
            $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;
            $snapshot = is_array($metadata) && is_array($metadata['course_title'] ?? null) ? $metadata['course_title'] : [];
            $other = $context->isArabic() ? 'en' : 'ar';
            $printed = Employee::text($snapshot[$context->locale] ?? null) ?? Employee::text($snapshot[$other] ?? null)
                ?? LocalizedJson::pick($row->title_for_certificate, $context->locale)
                ?? LocalizedJson::pick($row->course_title, $context->locale);

            return self::shape([
                ...Employee::values($row, $context),
                'certificate_no' => Employee::text($row->certificate_number),
                'certificate_name' => $printed,
                'linked_course' => LocalizedJson::pick($row->course_title, $context->locale),
                'issue_date' => Employee::date($row->issued_on),
                'certificate_status' => __($row->certificate_state === 'revoked' ? self::STATUSES['revoked'] : self::STATUSES['valid']),
                'issued_by' => $row->issued_by === null
                    ? __('reports.values.certificate.automatic')
                    : Employee::text($row->issuer_name),
            ], $keys);
        })->values()->all();
    }
}
