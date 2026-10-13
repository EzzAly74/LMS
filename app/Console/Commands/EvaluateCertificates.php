<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\CertificateProjectionService;
use App\Services\CertificateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-run the certificate decision (D-091, D-094) for every enrolment that
 * holds no active certificate: the same `CertificateService::evaluate()` the
 * live triggers call, so it issues exactly where the configured rule and the
 * completion event are met, and never for a revoked certificate.
 *
 * Use it once after D-094 (completion = certificate for every course) and
 * whenever eligibility data was changed outside the triggers (a cohort sheet
 * re-import, a rule change). `--dry-run` only counts.
 */
class EvaluateCertificates extends Command
{
    protected $signature = 'certificates:evaluate
        {--dry-run : Count who would receive a certificate; write nothing}
        {--course=* : Only these course ids}';

    protected $description = 'Issue the certificates learners are already eligible for (configured rule + completion event; never re-issues a revoked one).';

    public function handle(CertificateService $certificates, CertificateProjectionService $projection): int
    {
        $dry = (bool) $this->option('dry-run');
        $courseIds = array_map('intval', (array) $this->option('course'));

        $pending = DB::table('users_courses as uc')
            ->when($courseIds !== [], fn ($q) => $q->whereIn('uc.course_id', $courseIds))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('user_certificates as c')
                ->whereColumn('c.user_id', 'uc.user_id')->whereColumn('c.course_id', 'uc.course_id'))
            ->orderBy('uc.course_id')
            ->get(['uc.user_id', 'uc.course_id']);

        $checked = 0;
        $issued = 0;
        foreach ($pending->groupBy('course_id') as $courseId => $rows) {
            $course = Course::query()->find($courseId);
            if ($course === null) {
                continue;
            }
            foreach (User::query()->whereIn('id', $rows->pluck('user_id'))->get() as $user) {
                $checked++;
                if ($dry) {
                    // The live decision without writing: rule met and nothing yet issued.
                    $issued += $projection->meetsIssuanceCriteria($user, $course) ? 1 : 0;
                    continue;
                }
                $certificate = $certificates->evaluate($user, $course);
                $issued += $certificate !== null && $certificate->wasRecentlyCreated ? 1 : 0;
            }
        }

        $this->info(sprintf('%s: %d enrolments without a certificate checked, %d %s.',
            $dry ? 'Dry run' : 'Done', $checked, $issued,
            $dry ? 'meet the configured rule (the completion event is checked on a real run)' : 'certificates issued'));
        $this->line('Revoked certificates are never re-issued; enrolments that hold one are skipped.');

        return self::SUCCESS;
    }
}
