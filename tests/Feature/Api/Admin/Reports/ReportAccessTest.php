<?php

namespace Tests\Feature\Api\Admin\Reports;

use App\Models\Admin;
use App\Models\Course;
use App\Models\ReportExportLog;
use App\Models\User;
use App\Models\UserCertificate;
use App\Reports\ReportRegistry;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The reports module's contract (D-112): the catalogue, who may read what,
 * validation, course scope, and the export file.
 */
class ReportAccessTest extends ReportTestCase
{
    public function test_the_catalogue_lists_the_ten_reports_in_the_spec_order_with_their_columns(): void
    {
        $result = $this->getJson(self::BASE.'/admin/reports', $this->admin())->assertOk()->json('result');

        $this->assertSame(app(ReportRegistry::class)->keys(), array_column($result, 'key'));
        $this->assertSame('Compliance by Job Title', $result[0]['name']);
        $this->assertSame('One job title', $result[0]['row_unit']);
        $columns = collect($result[0]['columns'])->keyBy('key');
        $this->assertSame(['Job Title', 'Job Title Code', 'Department'], collect($result[0]['columns'])->take(3)->pluck('label')->all());
        // Not captured: the column keeps its place, flagged.
        $this->assertFalse($columns['job_title_code']['available']);
        $this->assertTrue($columns['compliance_pct']['available']);
        $this->assertSame('percent', $columns['compliance_pct']['type']);
        $this->assertSame(['departments', 'job_title_ids', 'as_of'], array_column($result[0]['filters'], 'key'));
    }

    public function test_the_catalogue_follows_the_language(): void
    {
        $result = $this->getJson(self::BASE.'/admin/reports', $this->admin() + ['Accept-Language' => 'ar'])->assertOk()->json('result');

        $this->assertSame('الامتثال حسب المسمى الوظيفي', $result[0]['name']);
        $this->assertSame('المسمى الوظيفي', $result[0]['columns'][0]['label']);
    }

    public function test_guests_learners_and_admins_without_view_reports_are_refused(): void
    {
        $this->assertUnauthorized($this->getJson(self::BASE.'/admin/reports'));
        $this->assertForbidden($this->getJson(self::BASE.'/admin/reports', $this->userToken()['headers']));
        $noReports = $this->adminWith(['view-dashboard']);
        $this->assertForbidden($this->getJson(self::BASE.'/admin/reports', $noReports));
        $this->assertForbidden($this->page('attendance', [], $noReports));
        $this->assertForbidden($this->getJson(self::BASE.'/admin/reports/attendance/export?format=csv', $noReports));
    }

    public function test_named_evaluation_responses_need_the_evaluations_permission_too(): void
    {
        $reportsOnly = $this->adminWith(['view-reports']);

        $keys = array_column($this->getJson(self::BASE.'/admin/reports', $reportsOnly)->assertOk()->json('result'), 'key');
        $this->assertNotContains('evaluation-responses', $keys);
        $this->assertContains('evaluation-summary', $keys);

        $template = DB::table('evaluation_categories')->insertGetId(['name' => json_encode(['en' => 'T', 'ar' => 'T']), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertForbidden($this->page('evaluation-responses', ['template_id' => $template], $reportsOnly));
        $this->assertForbidden($this->getJson(self::BASE."/admin/reports/evaluation-responses/export?format=csv&template_id={$template}", $reportsOnly));
        $this->page('evaluation-summary', ['template_id' => $template], $reportsOnly)->assertOk();

        $both = $this->adminWith(['view-reports', 'view-evaluations']);
        $this->page('evaluation-responses', ['template_id' => $template], $both)->assertOk();
    }

    public function test_an_unknown_report_or_option_source_is_not_found(): void
    {
        $this->page('no-such-report')->assertNotFound();
        $this->getJson(self::BASE.'/admin/reports/no-such-report/export', $this->admin())->assertNotFound();
        $this->getJson(self::BASE.'/admin/reports/options/users-passwords', $this->admin())->assertNotFound();
    }

    public function test_invalid_filters_are_422(): void
    {
        $this->page('attendance', ['per_page' => 101])->assertStatus(422);
        $this->page('attendance', ['per_page' => 0])->assertStatus(422);
        $this->page('completion', ['statuses' => ['done']])->assertStatus(422);
        $this->page('completion', ['enrolled_from' => '2026-05-10', 'enrolled_to' => '2026-05-01'])->assertStatus(422)->assertJsonValidationErrors('enrolled_to');
        $this->page('completion', ['enrolled_from' => '10/05/2026'])->assertStatus(422);
        $this->page('attendance', ['course_ids' => range(1, 101)])->assertStatus(422);
        $this->page('attendance', ['course_ids' => ['abc']])->assertStatus(422);
        $this->page('attendance', ['attendance_below' => 101])->assertStatus(422);
        $this->page('evaluation-summary')->assertStatus(422)->assertJsonValidationErrors('template_id');
        $this->page('evaluation-summary', ['template_id' => 999999])->assertStatus(422);
        $this->getJson(self::BASE.'/admin/reports/attendance/export?format=pdf', $this->admin())->assertStatus(422);
        // A valid open range is fine.
        $this->page('completion', ['enrolled_from' => '2026-05-01'])->assertOk();
    }

    public function test_a_scoped_account_sees_only_its_courses_and_cannot_name_another(): void
    {
        $mine = Course::factory()->create();
        $other = Course::factory()->create();
        $instructor = $this->instructor()->id;
        DB::table('courses_instructors')->insert(['course_id' => $mine->id, 'instructor_id' => $instructor]);
        foreach ([$mine, $other] as $course) {
            UserCertificate::factory()->create(['course_id' => $course->id]);
        }
        $scoped = $this->adminWith(['view-reports'], fn ($role) => DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']));
        $admin = Admin::query()->latest('id')->first();
        $admin->forceFill(['instructor_id' => $instructor])->save();

        $rows = $this->page('certificate-status', ['per_page' => 100], $scoped)->assertOk()->json('result.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($mine->fresh()->getTranslation('title', 'en'), $rows[0]['linked_course']);

        $this->page('certificate-status', ['course_ids' => [$other->id]], $scoped)->assertNotFound();
        $options = $this->getJson(self::BASE.'/admin/reports/options/courses', $scoped)->assertOk()->json('result');
        $this->assertSame([$mine->id], array_column($options, 'id'));
    }

    public function test_the_page_carries_columns_rows_and_paging(): void
    {
        UserCertificate::factory()->count(3)->create();

        $result = $this->page('certificate-status', ['per_page' => 2, 'page' => 2])->assertOk()->json('result');

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['last_page']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(array_column($result['columns'], 'key'), array_keys($result['rows'][0]));
        // Unavailable columns are always empty.
        $this->assertNull($result['rows'][0]['line_manager']);
        $this->assertNull($result['rows'][0]['verification_link']);
    }

    public function test_the_csv_export_has_the_file_header_then_the_columns_and_is_logged(): void
    {
        $user = User::factory()->create(['name_en' => 'Sara Ali', 'name_ar' => 'سارة علي']);
        UserCertificate::factory()->create(['user_id' => $user->id]);
        $admin = Admin::factory()->create(['name' => 'Report Reader']);
        $headers = $this->adminToken($admin)['headers'];

        $file = $this->csv('certificate-status', ['statuses' => ['valid']], 'en', $headers);

        $this->assertSame('Certificate Status', $file['header']['Report Name']);
        $this->assertSame('Report Reader', $file['header']['Generated By']);
        $this->assertSame('Status = Valid', $file['header']['Filters Applied']);
        $this->assertSame('English', $file['header']['Language']);
        $this->assertStringContainsString('Line Manager', $file['header']['Not captured yet']);
        $this->assertStringContainsString('Verification Link', $file['header']['Not captured yet']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $file['header']['Generated At']);
        $this->assertSame('Employee ID', $file['headings'][0]);
        $this->assertCount(1, $file['rows']);
        $this->assertSame('Sara Ali', $file['rows'][0]['Full Name (EN)']);
        $this->assertSame('Valid', $file['rows'][0]['Status']);
        // No totals row.
        $this->assertNotContains('Total', array_column($file['rows'], 'Employee ID'));

        $log = ReportExportLog::query()->latest('id')->firstOrFail();
        $this->assertSame(['certificate-status', 'csv', 1, 'en', ['statuses' => ['valid']], (int) $admin->id],
            [$log->report_type, $log->format, $log->row_count, $log->locale, $log->filters, (int) $log->exported_by_admin_id]);
    }

    public function test_the_arabic_export_is_in_arabic(): void
    {
        UserCertificate::factory()->create();

        $file = $this->csv('certificate-status', [], 'ar');

        $this->assertSame('حالة الشهادات', $file['header']['اسم التقرير']);
        $this->assertSame('العربية', $file['header']['اللغة']);
        $this->assertSame('لا يوجد', $file['header']['عوامل التصفية المطبقة']);
        $this->assertSame('الكود الوظيفي', $file['headings'][0]);
    }

    public function test_a_value_that_looks_like_a_formula_stays_text(): void
    {
        $user = User::factory()->create(['name_en' => '=HYPERLINK("http://x","y")']);
        UserCertificate::factory()->create(['user_id' => $user->id]);

        $row = $this->csv('certificate-status')['rows'][0];

        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $row['Full Name (EN)']);
    }

    public function test_the_xlsx_export_opens_with_the_header_typed_cells_and_right_to_left_in_arabic(): void
    {
        $user = User::factory()->create(['name_en' => '=1+1']);
        UserCertificate::factory()->create(['user_id' => $user->id]);

        $response = $this->get(self::BASE.'/admin/reports/certificate-status/export?format=xlsx', $this->admin() + ['Accept-Language' => 'ar'])->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/NAS-certificate-status-.*\.xlsx/', (string) $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('اسم التقرير', $sheet->getCell('A1')->getValue());
        $this->assertSame('حالة الشهادات', $sheet->getCell('B1')->getValue());
        // Headings after the header block and a blank row; the data cell is text, not a formula.
        $headingRow = 8;
        $this->assertSame('الكود الوظيفي', $sheet->getCell([1, $headingRow])->getValue());
        $this->assertSame('=1+1', $sheet->getCell([2, $headingRow + 1])->getValue());
        $this->assertSame('s', $sheet->getCell([2, $headingRow + 1])->getDataType());
    }

    public function test_exports_are_rate_limited(): void
    {
        $headers = $this->admin();
        for ($i = 0; $i < 10; $i++) {
            $this->get(self::BASE.'/admin/reports/certificate-status/export?format=csv', $headers)->assertOk();
        }
        $this->get(self::BASE.'/admin/reports/certificate-status/export?format=csv', $headers)->assertStatus(429);
    }

    public function test_a_page_costs_the_same_queries_for_5_or_25_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page('certificate-status', ['per_page' => 100])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        UserCertificate::factory()->count(5)->create();
        // Warm up: the auth middleware's once-a-minute "last active" writes.
        $count();
        $small = $count();
        UserCertificate::factory()->count(20)->create();

        $this->assertSame($small, $count());
    }
}
