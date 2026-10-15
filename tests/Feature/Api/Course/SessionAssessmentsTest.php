<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
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
 * Quizzes and assignments act as modules (NEW2B-6358, D-109): the schedule
 * sheet's "content" column takes their number in the course Content tab, like
 * a module's (Q<id> / A<id> codes are also read). In these fixtures the
 * Content tab is: 1 Module 1, 2 Module 2, 3 the quiz, 4 the assignment;
 * the learner meets them inside that session, and the course Content tab
 * lists all three in one numbered list (Figma 342:11813, 913:47084).
 */
class SessionAssessmentsTest extends ApiTestCase
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

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([self::HEADER, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'sch').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return array{Course, CourseLecture, CourseLecture, CourseExam, CourseAssignment} */
    private function course(): array
    {
        $course = Course::factory()->create();
        DB::table('courses')->where('id', $course->id)->update(['number_of_sessions' => 2, 'hours' => 1]);
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $m1 = CourseLecture::factory()->create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => ['en' => 'Module 1', 'ar' => 'المحتوى 1']]);
        $m2 = CourseLecture::factory()->create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => ['en' => 'Module 2', 'ar' => 'المحتوى 2']]);

        $quiz = CourseExam::factory()->create(['course_id' => $course->id, 'section_id' => null, 'title' => ['en' => 'Knowledge Check', 'ar' => 'اختبار المعرفة'], 'cohort_scope' => 'all']);
        CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 0, 'type' => 'mcq', 'score' => 5,
            'question' => 'Q', 'question_en' => 'Q', 'options_en' => ['a', 'b'], 'correct_answer_en' => 'a']);
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id, 'title' => 'Practical Assessment', 'title_ar' => 'تقييم عملي']);
        CourseAssignmentQuestion::create(['course_assignment_id' => $assignment->id, 'position' => 0, 'type' => 'open', 'score' => 20, 'question_en' => 'Why?']);

        return [$course->refresh(), $m1, $m2, $quiz, $assignment];
    }

    private function create(Course $course, UploadedFile $file): TestResponse
    {
        return $this->post(self::BASE."/courses/{$course->id}/sections/scheduled", [
            'name' => ['en' => 'Cohort A', 'ar' => 'الدفعة أ'], 'capacity' => 25, 'schedule' => $file,
        ], $this->headers + ['Accept' => 'application/json']);
    }

    /** @return array<string, string> */
    private function learnerIn(Course $course, int $cohortId): array
    {
        $user = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohortId]);

        return $this->userToken($user)['headers'];
    }

    public function test_a_session_lists_quizzes_and_assignments_by_their_content_tab_number_in_sheet_order(): void
    {
        [$course, $m1, $m2, $quiz, $assignment] = $this->course();

        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', '1, 3'],
            // A Q / A code is read too.
            [2, '2026-10-27', '09:00', '11:00', '', "a{$assignment->id}، 2"],
        ]))->assertCreated()->json('result.id');

        $sessions = DB::table('course_sessions')->where('section_id', $id)->orderBy('session_date')->pluck('id')->all();
        $this->assertSame([[$quiz->id, 2]], DB::table('course_session_exams')->where('session_id', $sessions[0])->get(['exam_id', 'position'])->map(fn ($r) => [(int) $r->exam_id, (int) $r->position])->all());
        $this->assertSame([[$assignment->id, 1]], DB::table('course_session_assignments')->where('session_id', $sessions[1])->get(['assignment_id', 'position'])->map(fn ($r) => [(int) $r->assignment_id, (int) $r->position])->all());

        // The edit template writes them back as Content-tab numbers, in order.
        $response = $this->get(self::BASE."/courses/{$course->id}/sections/{$id}/schedule-template", $this->headers)->assertOk();
        $book = Excel::toArray(null, $response->baseResponse->getFile()->getPathname(), null, \Maatwebsite\Excel\Excel::XLSX);
        $this->assertSame('1, 3', (string) $book[0][1][5]);
        $this->assertSame('4, 2', (string) $book[0][2][5]);

        // The reference sheet is the Content tab, numbered.
        $this->assertSame(['number', 'title_en', 'title_ar', 'type'], $book[1][0]);
        $this->assertSame([
            [1, 'Module 1', 'المحتوى 1', 'module'],
            [2, 'Module 2', 'المحتوى 2', 'module'],
            [3, 'Knowledge Check', 'اختبار المعرفة', 'quiz'],
            [4, 'Practical Assessment', 'تقييم عملي', 'assignment'],
        ], array_map(fn ($r) => [(int) $r[0], $r[1], $r[2], $r[3]], array_slice($book[1], 1)));
    }

    public function test_a_number_past_the_content_tab_another_courses_quiz_or_a_bad_code_creates_nothing(): void
    {
        [$course, $m1] = $this->course();
        [, , , $foreignQuiz] = $this->course();

        $errors = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', "1, 5, Q{$foreignQuiz->id}"],
            [2, '2026-10-27', '09:00', '11:00', '', 'X3'],
        ]))->assertStatus(422)->json('report.errors');

        $this->assertSame("Not in this course's Content tab: 5, Q{$foreignQuiz->id}. Use numbers from 1 to 4 (see the modules sheet).", $errors[0]['message']);
        $this->assertSame('content', $errors[1]['column']);
        $this->assertSame(0, DB::table('course_sections')->where('course_id', $course->id)->where('name->en', 'Cohort A')->count());
        $this->assertSame(0, DB::table('course_session_exams')->count());
    }

    public function test_the_player_shows_a_scheduled_quiz_inside_its_session_and_keeps_an_unscheduled_one_at_the_end(): void
    {
        [$course, $m1, $m2, $quiz, $assignment] = $this->course();
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', '1'],
            [2, '2026-10-27', '09:00', '11:00', '', '3, 2'],
        ]))->assertCreated()->json('result.id');

        $weeks = $this->getJson(self::BASE."/my/courses/{$course->id}/outline", $this->learnerIn($course, $id))->assertOk()->json('result.weeks');
        $view = array_map(fn ($w) => [$w['label'], array_map(fn ($i) => $i['kind'].':'.$i['id'], $w['items'])], $weeks);

        $this->assertSame([
            ['Week 1', ["lecture:{$m1->id}"]],
            ['Week 2', ["quiz:{$quiz->id}", "lecture:{$m2->id}"]],
            ['Assessments', ["assignment:{$assignment->id}"]],
        ], $view);
    }

    public function test_scheduling_a_specific_cohort_quiz_reaches_that_cohort_only(): void
    {
        [$course, $m1, , $quiz] = $this->course();
        // Made for one other cohort; this new cohort cannot be among them yet.
        $other = CourseSection::factory()->create(['course_id' => $course->id]);
        $quiz->update(['cohort_scope' => 'specific']);
        DB::table('course_exam_cohorts')->insert(['course_exam_id' => $quiz->id, 'course_section_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);

        $scheduled = $this->create($course, $this->xlsx([[1, '2026-10-20', '09:00', '11:00', '', '3']]))->assertCreated()->json('result.id');
        $unscheduled = CourseSection::factory()->create(['course_id' => $course->id]);

        $kinds = fn (array $headers) => collect($this->getJson(self::BASE."/my/courses/{$course->id}/outline", $headers)->assertOk()->json('result.weeks'))
            ->flatMap(fn ($w) => $w['items'])->where('kind', 'quiz')->pluck('id')->all();

        $this->assertSame([$quiz->id], $kinds($this->learnerIn($course, $scheduled)));
        $this->assertSame([], $kinds($this->learnerIn($course, $unscheduled->id)));
        $this->assertSame([$quiz->id], $kinds($this->learnerIn($course, $other->id)));
    }

    public function test_the_website_course_detail_returns_each_sessions_contents_and_keeps_content_ids(): void
    {
        [$course, $m1, $m2, $quiz, $assignment] = $this->course();
        $id = $this->create($course, $this->xlsx([
            [1, '2026-10-20', '09:00', '11:00', '', '1, 3, 4, 2'],
        ]))->assertCreated()->json('result.id');

        ['headers' => $learner] = $this->userToken();
        $body = $this->getJson(self::BASE."/learner/academy/courses/{$course->id}", $learner)->assertOk()->json('result');
        $session = collect($body['cohorts'])->firstWhere('id', $id)['sessions'][0];

        $this->assertSame([$m1->id, $m2->id], $session['content_ids']); // modules only, as before
        $this->assertSame(["lecture-{$m1->id}", "quiz-{$quiz->id}", "assignment-{$assignment->id}", "lecture-{$m2->id}"], array_column($session['contents'], 'key'));
        // Every key is one of the course's content items.
        $keys = array_column($body['content'], 'key');
        foreach ($session['contents'] as $c) {
            $this->assertContains($c['key'], $keys);
        }
    }

    public function test_the_content_tab_lists_modules_quizzes_and_assignments_with_their_codes(): void
    {
        [$course, $m1, $m2, $quiz, $assignment] = $this->course();
        // Added later: listed after the others (the list is in the order content was added).
        $draft = CourseExam::factory()->draft()->create(['course_id' => $course->id, 'section_id' => null, 'created_at' => now()->addMinute()]);

        $rows = $this->getJson(self::BASE."/courses/{$course->id}/content", $this->headers)->assertOk()->json('result');

        $this->assertSame(
            ["lecture:{$m1->id}:{$m1->id}", "lecture:{$m2->id}:{$m2->id}", "quiz:{$quiz->id}:Q{$quiz->id}", "assignment:{$assignment->id}:A{$assignment->id}", "quiz:{$draft->id}:Q{$draft->id}"],
            array_map(fn ($r) => "{$r['kind']}:{$r['id']}:{$r['code']}", $rows),
        );
        $this->assertSame('Knowledge Check', $rows[2]['title']);
        $this->assertSame(60, $rows[2]['duration_minutes']);
        $this->assertSame('draft', $rows[4]['status']);
        $this->assertSame('تقييم عملي', $this->getJson(self::BASE."/courses/{$course->id}/content", $this->headers + ['Accept-Language' => 'ar'])->json('result.3.title'));
    }

    public function test_who_may_read_the_content_tab(): void
    {
        [$course] = $this->course();

        // A learner: not an admin.
        $this->getJson(self::BASE."/courses/{$course->id}/content", $this->userToken()['headers'])->assertStatus(403);

        // An instructor's account limited to their courses (D-074): theirs
        // is listed, another course is not found.
        $instructor = \App\Models\Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona-'.uniqid().'@academy.test']);
        [$theirs] = $this->course();
        $theirs->instructors()->attach($instructor->id);
        $role = \Spatie\Permission\Models\Role::findOrCreate('scope-'.uniqid(), 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']);
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-courses', 'admin'));
        $admin = Admin::factory()->create();
        $admin->forceFill(['instructor_id' => $instructor->id])->save();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson(self::BASE."/courses/{$theirs->id}/content", $headers)->assertOk()->assertJsonCount(4, 'result');
        $this->getJson(self::BASE."/courses/{$course->id}/content", $headers)->assertNotFound();
    }
}
