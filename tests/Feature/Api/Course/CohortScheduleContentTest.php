<?php

namespace Tests\Feature\Api\Course;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use Database\Seeders\MobileSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Api\ApiTestCase;

/**
 * New curriculum flow (D-079): modules belong to the course; the cohort
 * schedule sheet's "content" column says which modules each session covers.
 * The Website Curriculum tab (Figma 807:40170) and the course player read it.
 */
class CohortScheduleContentTest extends ApiTestCase
{
    private const HEADER = ['session_no', 'date', 'start_time', 'end_time', 'location', 'content'];

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->seed(MobileSettingSeeder::class);
        ['headers' => $this->headers] = $this->adminToken();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function xlsx(array $rows, array $header = self::HEADER): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([$header, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'sch').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return array{Course, list<CourseLecture>} */
    private function courseWithModules(int $count = 3): array
    {
        $course = Course::factory()->create();
        DB::table('courses')->where('id', $course->id)->update(['number_of_sessions' => 2, 'hours' => 1]);
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $modules = [];
        foreach (range(1, $count) as $n) {
            $modules[] = CourseLecture::factory()->create([
                'course_id'  => $course->id,
                'section_id' => $section->id,
                'title'      => ['en' => "Module {$n}", 'ar' => "المحتوى {$n}"],
            ]);
        }

        return [$course->refresh(), $modules];
    }

    private function create(Course $course, UploadedFile $file): TestResponse
    {
        return $this->post(self::BASE."/courses/{$course->id}/sections/scheduled", [
            'name' => ['en' => 'Cohort A', 'ar' => 'الدفعة أ'], 'capacity' => 25, 'schedule' => $file,
        ], $this->headers + ['Accept' => 'application/json']);
    }

    private function edit(Course $course, CourseSection $section, UploadedFile $file): TestResponse
    {
        return $this->post(self::BASE."/courses/{$course->id}/sections/{$section->id}/scheduled", [
            'name' => ['en' => 'Cohort A', 'ar' => 'الدفعة أ'], 'capacity' => 25, 'schedule' => $file,
        ], $this->headers + ['Accept' => 'application/json']);
    }

    /** @return array<string, list<int>> session date => module ids in sheet order */
    private function links(int $sectionId): array
    {
        $out = [];
        foreach (DB::table('course_sessions')->where('section_id', $sectionId)->orderBy('session_date')->get(['id', 'session_date']) as $s) {
            $out[substr((string) $s->session_date, 0, 10)] = DB::table('course_session_lectures')
                ->where('session_id', $s->id)->orderBy('position')->pluck('lecture_id')->map(fn ($id) => (int) $id)->all();
        }

        return $out;
    }

    // ───────────────────────────────────────────────────────────── template

    public function test_the_template_has_a_content_column_and_lists_only_this_courses_modules(): void
    {
        [$course, $modules] = $this->courseWithModules(2);
        [, $foreign]        = $this->courseWithModules(1);

        $response = $this->get(self::BASE."/courses/{$course->id}/sections/schedule-template", $this->headers)->assertOk();
        $sheets   = Excel::toArray(null, $response->baseResponse->getFile()->getPathname(), null, \Maatwebsite\Excel\Excel::XLSX);

        $this->assertSame(self::HEADER, $sheets[0][0]);
        $this->assertSame(['module_id', 'title_en', 'title_ar'], $sheets[1][0]);
        $this->assertSame([
            [$modules[0]->id, 'Module 1', 'المحتوى 1'],
            [$modules[1]->id, 'Module 2', 'المحتوى 2'],
        ], array_map(fn ($r) => [(int) $r[0], $r[1], $r[2]], array_slice($sheets[1], 1)));
        $this->assertNotContains($foreign[0]->id, array_map(fn ($r) => (int) $r[0], array_slice($sheets[1], 1)));
    }

    public function test_the_edit_template_carries_each_sessions_content(): void
    {
        [$course, $m] = $this->courseWithModules();
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', "{$m[2]->id}, {$m[0]->id}"],
            [2, '2026-10-27', '09:00', '11:00', '', ''],
        ]))->assertCreated()->json('result.id');

        $response = $this->get(self::BASE."/courses/{$course->id}/sections/{$id}/schedule-template", $this->headers)->assertOk();
        $rows     = Excel::toArray(null, $response->baseResponse->getFile()->getPathname(), null, \Maatwebsite\Excel\Excel::XLSX)[0];

        $this->assertSame("{$m[2]->id}, {$m[0]->id}", (string) $rows[1][5]);
        $this->assertSame('', (string) $rows[2][5]);
    }

    // ───────────────────────────────────────────────────────────── create

    public function test_new_cohort_links_each_session_to_its_modules_in_sheet_order(): void
    {
        [$course, $m] = $this->courseWithModules();

        $id = $this->create($course, $this->xlsx([
            // Arabic comma, semicolon and a repeat are all accepted.
            [1, '2026-10-20', '09:00', '11:00', '', "{$m[1]->id}، {$m[0]->id}; {$m[1]->id}"],
            [2, '2026-10-27', '09:00', '11:00', '', ''],
            [3, '2026-11-03', '09:00', '11:00', '', $m[2]->id], // a plain number cell
        ]))->assertCreated()->json('result.id');

        $this->assertSame([
            '2026-10-20' => [$m[1]->id, $m[0]->id],
            '2026-10-27' => [],
            '2026-11-03' => [$m[2]->id],
        ], $this->links($id));
    }

    public function test_a_sheet_without_the_content_column_still_works(): void
    {
        [$course] = $this->courseWithModules(1);

        $id = $this->create($course, $this->xlsx(
            [[1, '2026-10-20', '09:00', '11:00', 'Room 1']],
            ['session_no', 'date', 'start_time', 'end_time', 'location'],
        ))->assertCreated()->json('result.id');

        $this->assertSame(['2026-10-20' => []], $this->links($id));
    }

    public function test_another_courses_module_or_a_bad_cell_creates_nothing(): void
    {
        [$course, $m]  = $this->courseWithModules(1);
        [, $foreign]   = $this->courseWithModules(1);

        $report = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', "{$m[0]->id}, {$foreign[0]->id}"],
            [2, '2026-10-27', '09:00', '11:00', '', 'Module 1'],
            [3, '2026-11-03', '09:00', '11:00', '', implode(',', range(1, 51))],
        ]))->assertUnprocessable()->json('report.errors');

        $this->assertSame([[2, 'content'], [3, 'content'], [4, 'content']], array_map(fn ($e) => [$e['row'], $e['column']], $report));
        $this->assertStringContainsString((string) $foreign[0]->id, $report[0]['message']);
        $this->assertSame(0, CourseSection::query()->where('course_id', $course->id)->where('name->en', 'Cohort A')->count());
        $this->assertSame(0, DB::table('course_session_lectures')->count());
    }

    // ───────────────────────────────────────────────────────────── edit

    public function test_editing_changes_content_of_held_and_upcoming_sessions_and_an_empty_cell_clears_it(): void
    {
        [$course, $m] = $this->courseWithModules();
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-01', '09:00', '11:00', '', $m[0]->id], // already held on 10 Oct
            [2, '2026-10-20', '09:00', '11:00', '', $m[1]->id],
        ]))->assertCreated()->json('result.id');
        $section = CourseSection::query()->findOrFail($id);

        $result = $this->edit($course, $section, $this->xlsx([
            [1, '2026-10-01', '09:00', '11:00', '', "{$m[0]->id}, {$m[2]->id}"],
            [2, '2026-10-20', '09:00', '11:00', '', ''],
            [3, '2026-10-27', '09:00', '11:00', '', $m[1]->id],
        ]))->assertOk()->json('result');

        $this->assertSame(1, $result['sessions_added']);
        $this->assertSame(2, $result['sessions_updated']);
        $this->assertSame([
            '2026-10-01' => [$m[0]->id, $m[2]->id],
            '2026-10-20' => [],
            '2026-10-27' => [$m[1]->id],
        ], $this->links($id));
    }

    public function test_an_unchanged_sheet_is_still_nothing_new(): void
    {
        [$course, $m] = $this->courseWithModules(1);
        $rows = [[1, '2026-10-20', '09:00', '11:00', '', $m[0]->id]];
        $id   = $this->create($course, $this->xlsx($rows))->assertCreated()->json('result.id');

        $this->edit($course, CourseSection::query()->findOrFail($id), $this->xlsx($rows))->assertUnprocessable();
    }

    public function test_deleting_a_module_removes_it_from_the_schedule(): void
    {
        [$course, $m] = $this->courseWithModules(2);
        $id = $this->create($course, $this->xlsx([[1, '2026-10-20', '09:00', '11:00', '', "{$m[0]->id}, {$m[1]->id}"]]))
            ->assertCreated()->json('result.id');

        $this->deleteJson(self::BASE."/courses/{$course->id}/lectures/{$m[0]->id}", [], $this->headers)->assertSuccessful();

        $this->assertSame(['2026-10-20' => [$m[1]->id]], $this->links($id));
    }

    // ───────────────────────────────────────────────── module form fields

    public function test_modules_no_longer_take_or_return_scope_or_session_number(): void
    {
        [$course] = $this->courseWithModules(0);

        $result = $this->postJson(self::BASE."/courses/{$course->id}/lectures", [
            'title'          => ['en' => 'Intro', 'ar' => 'مقدمة'],
            'content_type'   => 'link',
            'video'          => 'https://example.com/intro',
            'learner_scope'  => 'cohort',
            'session_id'     => 999999,
            'session_number' => 4,
        ], $this->headers)->assertSuccessful()->json('result');

        $this->assertArrayNotHasKey('learner_scope', $result);
        $this->assertArrayNotHasKey('session_id', $result);
        $this->assertArrayNotHasKey('session_number', $result);
        $row = DB::table('course_lectures')->where('id', $result['id'])->first();
        $this->assertSame('all', $row->learner_scope);
        $this->assertNull($row->session_id);
        $this->assertNull($row->session_number);
    }

    // ───────────────────────────────────────────────── learner views

    public function test_the_website_course_detail_returns_each_sessions_content_ids(): void
    {
        [$course, $m] = $this->courseWithModules(2);
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', "{$m[1]->id}, {$m[0]->id}"],
            [2, '2026-10-27', '09:00', '11:00', '', ''],
        ]))->assertCreated()->json('result.id');

        ['headers' => $learner] = $this->userToken();
        $body = $this->getJson(self::BASE."/learner/academy/courses/{$course->id}", $learner)->assertOk()->json('result');

        $cohort = collect($body['cohorts'])->firstWhere('id', $id);
        $this->assertSame([[$m[1]->id, $m[0]->id], []], array_column($cohort['sessions'], 'content_ids'));
        // The mobile contract keeps these keys; every module is course-wide now.
        $this->assertSame(['all'], array_values(array_unique(array_column($body['units'], 'learner_scope'))));
    }

    public function test_the_course_player_groups_modules_under_the_learners_sessions(): void
    {
        [$course, $m] = $this->courseWithModules();
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', $m[1]->id],
            [2, '2026-10-27', '09:00', '11:00', '', "{$m[0]->id}, {$m[1]->id}"],
        ]))->assertCreated()->json('result.id');

        $user = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $id]);
        ['headers' => $learner] = $this->userToken($user);

        $weeks = $this->getJson(self::BASE."/my/courses/{$course->id}/outline", $learner)->assertOk()->json('result.weeks');
        $lectures = collect($weeks)->map(fn ($w) => [
            'label' => $w['label'] ?? $w['title'] ?? null,
            'ids'   => collect($w['items'])->where('kind', 'lecture')->pluck('id')->all(),
        ])->filter(fn ($w) => $w['ids'] !== [])->values()->all();

        // Module 2 sits under its first session; Module 3 is in no session.
        $this->assertSame([$m[1]->id], $lectures[0]['ids']);
        $this->assertSame([$m[0]->id], $lectures[1]['ids']);
        $this->assertSame([$m[2]->id], $lectures[2]['ids']);
        $this->assertSame('Session 1', $lectures[0]['label']);
        $this->assertSame('Session 2', $lectures[1]['label']);
    }
}
