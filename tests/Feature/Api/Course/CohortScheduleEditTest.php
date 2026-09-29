<?php

namespace Tests\Feature\Api\Course;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Api\ApiTestCase;

/**
 * Edit Cohort in the New Cohort dialog: names, capacity and the schedule sheet
 * again, of which only the new sessions are added. Sessions already held can
 * never change; the full schedule and a sheet of only new rows both work.
 */
class CohortScheduleEditTest extends ApiTestCase
{
    private const HEADER = ['session_no', 'date', 'start_time', 'end_time', 'location'];

    /** Held: 1 and 8 Oct. Upcoming: 15 Oct. "Now" is 10 Oct, noon. */
    private const HELD_1   = [1, '2026-10-01', '09:00', '11:00', 'Room A'];
    private const HELD_2   = [2, '2026-10-08', '09:00', '11:00', 'Room A'];
    private const UPCOMING = [3, '2026-10-15', '09:00', '11:00', 'Room A'];

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        ['headers' => $this->headers] = $this->adminToken();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([self::HEADER, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'sch').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** A cohort made the real way, through the New Cohort upload. */
    private function cohort(): array
    {
        $course = Course::factory()->create();
        DB::table('courses')->where('id', $course->id)->update(['number_of_sessions' => 3, 'hours' => 1]);
        $id = $this->post(self::BASE."/courses/{$course->id}/sections/scheduled", [
            'name' => ['en' => 'Cohort A', 'ar' => 'الدفعة أ'], 'capacity' => 25,
            'schedule' => $this->xlsx([self::HELD_1, self::HELD_2, self::UPCOMING]),
        ], $this->headers + ['Accept' => 'application/json'])->assertCreated()->json('result.id');

        return [$course->refresh(), CourseSection::query()->findOrFail($id)];
    }

    private function edit(Course $course, CourseSection $section, ?UploadedFile $file, array $extra = [], ?array $headers = null): TestResponse
    {
        $body = array_merge(['name' => ['en' => 'Cohort A2', 'ar' => 'الدفعة أ٢'], 'capacity' => 30], $extra);
        if ($file !== null) {
            $body['schedule'] = $file;
        }

        return $this->post(self::BASE."/courses/{$course->id}/sections/{$section->id}/scheduled", $body, ($headers ?? $this->headers) + ['Accept' => 'application/json']);
    }

    /** @return list<array{string, string, string, ?string, string}> date, from, to, location, title */
    private function sessions(CourseSection $section): array
    {
        return DB::table('course_sessions')->where('section_id', $section->id)
            ->orderBy('session_date')->orderBy('time_from')->get()
            ->map(fn ($s) => [(string) $s->session_date, substr((string) $s->time_from, 0, 5), substr((string) $s->time_to, 0, 5), $s->location, (string) $s->title])
            ->all();
    }

    private function ids(CourseSection $section): array
    {
        return DB::table('course_sessions')->where('section_id', $section->id)->orderBy('id')->pluck('id')->all();
    }

    // ─────────────────────────────────────────────────────────── merge

    public function test_the_full_schedule_with_extra_rows_adds_only_the_new_sessions(): void
    {
        [$course, $section] = $this->cohort();
        $before = $this->ids($section);

        $this->edit($course, $section, $this->xlsx([
            self::HELD_1, self::HELD_2, self::UPCOMING,
            [4, '2026-10-22', '09:00', '11:00', 'Room B'],
            [5, '2026-10-12', '14:00', '15:30', ''], // between held and upcoming
        ]))->assertOk()
            ->assertJsonPath('result.sessions_added', 2)
            ->assertJsonPath('result.sessions_updated', 0)
            ->assertJsonPath('result.section.number_of_sessions', 5);

        // The old sessions are the same rows (attendance stays attached).
        $this->assertSame($before, array_slice($this->ids($section), 0, 3));
        $this->assertSame([
            ['2026-10-01', '09:00', '11:00', 'Room A', 'Session 1'],
            ['2026-10-08', '09:00', '11:00', 'Room A', 'Session 2'],
            ['2026-10-12', '14:00', '15:30', null, 'Session 3'],
            ['2026-10-15', '09:00', '11:00', 'Room A', 'Session 4'], // renumbered, still upcoming
            ['2026-10-22', '09:00', '11:00', 'Room B', 'Session 5'],
        ], $this->sessions($section));

        $section->refresh();
        $this->assertSame('Cohort A2', $section->getTranslation('name', 'en'));
        $this->assertSame(30, (int) $section->capacity);
        $this->assertSame('2026-10-22', substr((string) $section->end_date, 0, 10));
        $this->assertSame('2026-10-01', substr((string) $section->start_date, 0, 10));
    }

    public function test_a_sheet_of_only_new_sessions_is_added_to_the_existing_ones(): void
    {
        [$course, $section] = $this->cohort();

        $this->edit($course, $section, $this->xlsx([[1, '2026-10-29', '09:00', '12:00', 'Hall']]))
            ->assertOk()->assertJsonPath('result.sessions_added', 1);

        $this->assertCount(4, $this->sessions($section));
        $this->assertSame(['2026-10-29', '09:00', '12:00', 'Hall', 'Session 4'], $this->sessions($section)[3]);
    }

    public function test_uploading_the_same_sheet_again_adds_nothing(): void
    {
        [$course, $section] = $this->cohort();
        $sheet = fn () => $this->xlsx([self::HELD_1, self::HELD_2, self::UPCOMING, [4, '2026-10-22', '09:00', '11:00', '']]);

        $this->edit($course, $section, $sheet())->assertOk();
        $this->edit($course, $section, $sheet())->assertStatus(422)
            ->assertJsonPath('report.errors.0.message', __('messages.schedule_nothing_new'));
        $this->assertCount(4, $this->sessions($section));
    }

    // ───────────────────────────────────────────── held sessions are locked

    public function test_a_row_that_would_change_a_held_session_is_refused_and_nothing_changes(): void
    {
        [$course, $section] = $this->cohort();
        $before = $this->sessions($section);

        // 8 Oct moved to 10:00 reads as a new session in the past.
        $this->edit($course, $section, $this->xlsx([self::HELD_1, [2, '2026-10-08', '10:00', '11:00', 'Room A'], self::UPCOMING, [4, '2026-10-22', '09:00', '11:00', '']]))
            ->assertStatus(422)
            ->assertJsonPath('report.errors.0.row', 3)
            ->assertJsonPath('report.errors.0.column', 'date')
            ->assertJsonPath('report.errors.0.message', __('messages.schedule_new_session_in_past'));

        // All or nothing: not the valid new row, not the name.
        $this->assertSame($before, $this->sessions($section));
        $this->assertSame('Cohort A', $section->refresh()->getTranslation('name', 'en'));
    }

    public function test_a_session_under_way_today_counts_as_started(): void
    {
        [$course, $section] = $this->cohort();

        $this->edit($course, $section, $this->xlsx([[1, '2026-10-10', '11:00', '13:00', '']]))
            ->assertStatus(422)->assertJsonPath('report.errors.0.message', __('messages.schedule_new_session_in_past'));
        $this->edit($course, $section, $this->xlsx([[1, '2026-10-10', '15:00', '16:00', '']]))
            ->assertOk()->assertJsonPath('result.sessions_added', 1);
    }

    public function test_only_an_upcoming_session_may_change_its_location(): void
    {
        [$course, $section] = $this->cohort();

        $this->edit($course, $section, $this->xlsx([[1, '2026-10-01', '09:00', '11:00', 'Room Z']]))
            ->assertStatus(422)
            ->assertJsonPath('report.errors.0.column', 'location')
            ->assertJsonPath('report.errors.0.message', __('messages.schedule_held_session_locked'));

        $this->edit($course, $section, $this->xlsx([[3, '2026-10-15', '09:00', '11:00', 'Room Z']]))
            ->assertOk()->assertJsonPath('result.sessions_added', 0)->assertJsonPath('result.sessions_updated', 1);
        $this->assertSame('Room Z', $this->sessions($section)[2][3]);
    }

    public function test_a_new_session_may_not_overlap_an_existing_one(): void
    {
        [$course, $section] = $this->cohort();

        $this->edit($course, $section, $this->xlsx([[4, '2026-10-15', '10:30', '12:00', '']]))
            ->assertStatus(422)
            ->assertJsonPath('report.errors.0.column', 'start_time')
            ->assertJsonPath('report.errors.0.message', __('messages.schedule_overlap_existing', ['date' => '2026-10-15', 'from' => '09:00', 'to' => '11:00']));
        // Back to back is fine.
        $this->edit($course, $section, $this->xlsx([[4, '2026-10-15', '11:00', '12:00', '']]))->assertOk();
    }

    // ─────────────────────────────────────────────── without a file

    public function test_without_a_file_only_the_name_and_capacity_change(): void
    {
        [$course, $section] = $this->cohort();
        $before = $this->sessions($section);

        $this->edit($course, $section, null, ['capacity' => 40])->assertOk()
            ->assertJsonPath('result.sessions_added', 0)
            ->assertJsonPath('result.section.capacity', 40);
        $this->assertSame($before, $this->sessions($section));
        $this->assertSame('الدفعة أ٢', $section->refresh()->getTranslation('name', 'ar'));
    }

    public function test_the_capacity_cannot_drop_below_the_enrolled_learners(): void
    {
        [$course, $section] = $this->cohort();
        foreach (User::factory()->count(3)->create() as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'group_id' => $section->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->edit($course, $section, null, ['capacity' => 2])->assertStatus(422)->assertJsonValidationErrors('capacity');
        $this->edit($course, $section, null, ['capacity' => 3])->assertOk();
    }

    public function test_invalid_edits_are_refused(): void
    {
        [$course, $section] = $this->cohort();

        $this->edit($course, $section, null, ['name' => ['en' => '', 'ar' => 'x']])->assertStatus(422)->assertJsonValidationErrors('name.en');
        $txt = UploadedFile::fake()->createWithContent('schedule.xlsx', 'not a spreadsheet');
        $this->edit($course, $section, $txt)->assertStatus(422)->assertJsonValidationErrors('schedule');
    }

    // ─────────────────────────────────── open for enrolment early (Q-073)

    private function futureCohort(bool $openEarly): array
    {
        $course = Course::factory()->create();
        $id = $this->post(self::BASE."/courses/{$course->id}/sections/scheduled", [
            'name' => ['en' => 'Later', 'ar' => 'لاحقًا'], 'capacity' => 20, 'open_for_enrollment' => $openEarly ? '1' : '0',
            'schedule' => $this->xlsx([[1, '2026-11-02', '09:00', '11:00', '']]),
        ], $this->headers + ['Accept' => 'application/json'])->assertCreated()->json('result.id');

        return [$course, CourseSection::query()->findOrFail($id)];
    }

    public function test_new_and_edit_set_open_for_enrolment_before_the_start(): void
    {
        [$course, $closed] = $this->futureCohort(false);
        [$openCourse, $open] = $this->futureCohort(true);
        $this->assertSame('scheduled', $closed->status);
        $this->assertSame('open_for_enrollment', $open->status);

        $this->edit($course, $closed, null, ['open_for_enrollment' => '1'])->assertOk()
            ->assertJsonPath('result.section.stored_status', 'open_for_enrollment');
        $this->edit($course, $closed, null, ['open_for_enrollment' => '0'])->assertOk();
        $this->assertSame('scheduled', $closed->refresh()->status);

        // Left out: unchanged.
        $this->edit($openCourse, $open, null)->assertOk();
        $this->assertSame('open_for_enrollment', $open->refresh()->status);
    }

    public function test_the_switch_does_not_move_a_started_or_inactive_cohort(): void
    {
        [$course, $started] = $this->cohort(); // began 1 Oct
        $this->edit($course, $started, null, ['open_for_enrollment' => '1'])->assertOk();
        $this->assertNotSame('open_for_enrollment', $started->refresh()->status);

        [$later, $future] = $this->futureCohort(false);
        DB::table('course_sections')->where('id', $future->id)->update(['status' => 'inactive']);
        $this->edit($later, $future, null, ['open_for_enrollment' => '1'])->assertOk();
        $this->assertSame('inactive', $future->refresh()->status);

        $this->edit($later, $future, null, ['open_for_enrollment' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('open_for_enrollment');
    }

    // ─────────────────────────────────────────────────────── template

    public function test_the_edit_template_starts_with_the_cohorts_sessions(): void
    {
        [$course, $section] = $this->cohort();

        $response = $this->get(self::BASE."/courses/{$course->id}/sections/{$section->id}/schedule-template", $this->headers)->assertOk();
        $rows = Excel::toArray(null, $response->baseResponse->getFile()->getPathname(), null, \Maatwebsite\Excel\Excel::XLSX)[0];

        $this->assertSame(self::HEADER, $rows[0]);
        $this->assertSame(['1', '2026-10-01', '09:00', '11:00', 'Room A'], array_map('strval', $rows[1]));
        $this->assertSame(['3', '2026-10-15', '09:00', '11:00', 'Room A'], array_map('strval', $rows[3]));
        // Then five blank numbered rows (the plan of 3 is met).
        $this->assertCount(1 + 3 + 5, $rows);
        $this->assertSame([4, null, null, null, null], [(int) $rows[4][0], $rows[4][1], $rows[4][2], $rows[4][3], $rows[4][4]]);
    }

    // ───────────────────────────────────────────────── authorization / IDOR

    public function test_another_courses_cohort_is_a_404_and_only_admins_may_edit(): void
    {
        [$course, $section] = $this->cohort();
        $other = Course::factory()->create();

        $this->edit($other, $section, null)->assertNotFound();
        $this->get(self::BASE."/courses/{$other->id}/sections/{$section->id}/schedule-template", $this->headers + ['Accept' => 'application/json'])->assertNotFound();

        $this->edit($course, $section, null, [], [])->assertUnauthorized();
        ['headers' => $learner] = $this->userToken();
        $this->edit($course, $section, null, [], $learner)->assertForbidden();
        $this->get(self::BASE."/courses/{$course->id}/sections/{$section->id}/schedule-template", $learner + ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame('Cohort A', $section->refresh()->getTranslation('name', 'en'));
    }
}
