<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Instructor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * D2 step 8: New Cohort with its schedule (Figma 2393:123167 / 2393:122292)
 * and course hours derived from the schedule (D-062, B-119).
 */
class CohortScheduleImportTest extends ApiTestCase
{
    private const HEADER = ['session_no', 'date', 'start_time', 'end_time', 'location'];

    private function course(int $sessions = 3): Course
    {
        $course = Course::factory()->create();
        DB::table('courses')->where('id', $course->id)->update(['number_of_sessions' => $sessions, 'hours' => 1]);

        return $course->refresh();
    }

    /** A real .xlsx built with PhpSpreadsheet; cells are written as given (strings stay strings). */
    private function xlsx(array $rows, array $header = self::HEADER): UploadedFile
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([$header, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'sch').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function url(Course $course, string $tail = 'scheduled'): string
    {
        return self::BASE."/courses/{$course->id}/sections/{$tail}";
    }

    private function send(Course $course, UploadedFile $file, array $headers, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->url($course), array_merge([
            'name' => ['en' => 'Cohort D', 'ar' => 'الدفعة د'], 'capacity' => 25, 'schedule' => $file,
        ], $extra), $headers + ['Accept' => 'application/json']);
    }

    // ───────────────────────────────────────────────────────────── template

    public function test_the_template_has_one_numbered_row_per_planned_session(): void
    {
        $course = $this->course(4);
        ['headers' => $headers] = $this->adminToken();

        $response = $this->get($this->url($course, 'schedule-template'), $headers)->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $rows = Excel::toArray(null, $path, null, \Maatwebsite\Excel\Excel::XLSX)[0];
        $this->assertSame(self::HEADER, $rows[0]);
        $this->assertSame([1, 2, 3, 4], array_map(static fn ($r) => (int) $r[0], array_slice($rows, 1)));
    }

    // ───────────────────────────────────────────────────────────── create

    public function test_a_valid_schedule_creates_the_cohort_its_sessions_and_the_course_hours(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();
        $file = $this->xlsx([
            [1, '2026-10-05', '09:00', '11:30', 'Room 3'],
            [2, '2026-10-12', '09:00', '11:30', ''],
            [3, '2026-10-19', '13:00', '14:00', 'Room 3'],
        ]);

        $result = $this->send($course, $file, $headers)->assertCreated()->json('result');

        $cohort = CourseSection::findOrFail($result['id']);
        $this->assertSame($course->id, $cohort->course_id);
        $this->assertSame('Cohort D', $cohort->getTranslation('name', 'en'));
        $this->assertSame('الدفعة د', $cohort->getTranslation('name', 'ar'));
        $this->assertSame(25, (int) $cohort->capacity);
        $this->assertSame('2026-10-05', substr((string) $cohort->start_date, 0, 10));
        $this->assertSame('2026-10-19', substr((string) $cohort->end_date, 0, 10));
        $this->assertSame(3, (int) $cohort->number_of_sessions);
        $this->assertEqualsWithDelta(2.0, (float) $cohort->avg_session_time, 0.01); // (2.5 + 2.5 + 1) / 3

        $sessions = DB::table('course_sessions')->where('section_id', $cohort->id)->orderBy('session_date')->get();
        $this->assertSame(['09:00:00', '09:00:00', '13:00:00'], $sessions->pluck('time_from')->all());
        $this->assertSame(['Room 3', null, 'Room 3'], $sessions->pluck('location')->all());

        $this->assertSame(6, (int) $course->refresh()->hours); // 2.5 + 2.5 + 1
    }

    public function test_rows_left_blank_in_the_template_are_skipped_and_capacity_defaults_to_thirty(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();
        $file = $this->xlsx([[1, '2026-10-05', '09:00', '10:00', ''], [2, '', '', '', ''], [3, '', '', '', '']]);

        $id = $this->post($this->url($course), ['name' => ['en' => 'A', 'ar' => 'أ'], 'schedule' => $file], $headers + ['Accept' => 'application/json'])
            ->assertCreated()->json('result.id');

        $this->assertSame(30, (int) CourseSection::find($id)->capacity);
        $this->assertSame(1, DB::table('course_sessions')->where('section_id', $id)->count());
    }

    public function test_real_excel_date_and_time_cells_are_accepted(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();
        $date = ExcelDate::PHPToExcel(new \DateTime('2026-11-02'));
        $file = $this->xlsx([[1, $date, 9.5 / 24, 12 / 24, '']]); // 09:30 - 12:00

        $id = $this->send($course, $file, $headers)->assertCreated()->json('result.id');

        $s = DB::table('course_sessions')->where('section_id', $id)->first();
        $this->assertSame('2026-11-02', substr((string) $s->session_date, 0, 10));
        $this->assertSame(['09:30:00', '12:00:00'], [$s->time_from, $s->time_to]);
        $this->assertSame(3, (int) $course->refresh()->hours); // 2.5 rounds to 3
    }

    public function test_any_bad_row_creates_nothing_and_lists_every_problem(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();
        $file = $this->xlsx([
            [1, '2026-10-05', '09:00', '11:00', ''],      // fine
            [2, '05/10/2026', '09:00', '11:00', ''],      // not YYYY-MM-DD
            [3, '2026-10-06', '11:00', '10:00', ''],      // ends before it starts
            [4, '2026-10-05', '10:30', '12:00', ''],      // overlaps row 2's date/time (row 2 of the sheet)
            [5, '2026-10-07', '25:00', '', ''],           // bad time, missing end
            [6, '2026-02-30', '09:00', '10:00', ''],      // no such date
        ]);

        $report = $this->send($course, $file, $headers)->assertStatus(422)->json('report.errors');

        $problems = array_map(static fn ($e) => $e['row'].':'.$e['column'], $report);
        $this->assertContains('3:date', $problems);
        $this->assertContains('4:end_time', $problems);
        $this->assertContains('5:start_time', $problems);
        $this->assertContains('6:start_time', $problems);
        $this->assertContains('6:end_time', $problems);
        $this->assertContains('7:date', $problems);
        $this->assertSame(0, CourseSection::query()->where('course_id', $course->id)->count());
        $this->assertSame(0, DB::table('course_sessions')->count());
        $this->assertSame(1, (int) $course->refresh()->hours);
    }

    public function test_a_schedule_without_sessions_or_required_columns_is_refused(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();

        $this->send($course, $this->xlsx([[1, '', '', '', '']]), $headers)->assertStatus(422)
            ->assertJsonPath('report.errors.0.row', 0);
        $this->send($course, $this->xlsx([[1, '2026-10-05']], ['session_no', 'date']), $headers)->assertStatus(422)
            ->assertJsonPath('report.errors.0.row', 1);
        $this->assertSame(0, CourseSection::query()->count());
    }

    // ───────────────────────────────────────────────────────── validation

    public function test_invalid_requests_are_refused(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();
        $ok = fn () => $this->xlsx([[1, '2026-10-05', '09:00', '10:00', '']]);

        $cases = [
            'no English name' => [['name' => ['ar' => 'أ']], 'name.en'],
            'no Arabic name'  => [['name' => ['en' => 'A']], 'name.ar'],
            'capacity zero'   => [['capacity' => 0], 'capacity'],
            'no file'         => [['schedule' => null], 'schedule'],
            'csv'             => [['schedule' => UploadedFile::fake()->createWithContent('s.csv', "date,start_time,end_time\n2026-10-05,09:00,10:00\n")], 'schedule'],
            'too big'         => [['schedule' => UploadedFile::fake()->create('s.xlsx', 8193, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')], 'schedule'],
            'script renamed'  => [['schedule' => UploadedFile::fake()->createWithContent('s.xlsx', '<?php echo 1; ?>')], 'schedule'],
        ];

        foreach ($cases as $label => [$extra, $field]) {
            $this->send($course, $ok(), $headers, $extra)->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
        }
        $this->assertSame(0, CourseSection::query()->count(), 'Nothing was created by a refused request.');
    }

    public function test_an_unknown_course_is_a_404(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->post(self::BASE.'/courses/999999/sections/scheduled', ['name' => ['en' => 'A', 'ar' => 'أ'], 'schedule' => $this->xlsx([[1, '2026-10-05', '09:00', '10:00', '']])], $headers + ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    // ───────────────────────────────────────────────────────── authorization

    public function test_only_admins_with_view_courses_may_download_or_upload(): void
    {
        $course = $this->course();
        $file   = fn () => $this->xlsx([[1, '2026-10-05', '09:00', '10:00', '']]);

        $this->send($course, $file(), [])->assertUnauthorized();
        $this->getJson($this->url($course, 'schedule-template'))->assertUnauthorized();

        ['headers' => $learner] = $this->userToken();
        $this->send($course, $file(), $learner)->assertForbidden();
        $this->get($this->url($course, 'schedule-template'), $learner + ['Accept' => 'application/json'])->assertForbidden();

        $blind = Admin::factory()->create();
        $blind->assignRole(tap(Role::findOrCreate('no-courses', 'admin'))->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        ['headers' => $restricted] = $this->adminToken($blind);
        $this->send($course, $file(), $restricted)->assertForbidden();
        $this->get($this->url($course, 'schedule-template'), $restricted + ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(0, CourseSection::query()->count());
    }

    // ─────────────────────────────────────────────────────── hours (D-062)

    public function test_hours_follow_the_newest_cohort_and_survive_a_course_edit(): void
    {
        $course = $this->course();
        ['headers' => $headers] = $this->adminToken();

        $this->send($course, $this->xlsx([[1, '2020-01-06', '09:00', '13:00', '']]), $headers)->assertCreated();
        $this->assertSame(4, (int) $course->refresh()->hours);

        // A later cohort with a shorter plan becomes the reference.
        $this->send($course, $this->xlsx([[1, '2020-03-02', '09:00', '11:00', ''], [2, '2020-03-09', '09:00', '10:00', '']]), $headers, ['name' => ['en' => 'E', 'ar' => 'هـ']])
            ->assertCreated();
        $this->assertSame(3, (int) $course->refresh()->hours);

        // Editing the course without sending hours no longer resets them to 1.
        $instructor = Instructor::query()->create(['name' => ['en' => 'T', 'ar' => 'م'], 'email' => 't'.uniqid().'@example.test']);
        $this->putJson(self::BASE."/courses/{$course->id}", [
            'title' => ['en' => 'Renamed', 'ar' => 'اسم'], 'description' => 'd', 'category_id' => $course->category_id,
            'certificate' => false, 'instructors' => [$instructor->id],
        ], $headers)->assertOk();
        $this->assertSame(3, (int) $course->refresh()->hours);
    }
}
