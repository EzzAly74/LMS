<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseExam;
use App\Models\CourseSection;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B2 (part 2) — admin learner detail, Figma 2181:115043.
 *
 * Per the Phase 1 capture the screen is: a profile card, five named tiles
 * ("Completed Courses, Active Courses, Active Course %, Last Quiz, Earned
 * Qualification"), an "Active & Completed Courses" table and a
 * "Quizzes / Assignments Performance" table, each with its own pager.
 */
class AdminLearnerDetailTest extends ApiTestCase
{
    private function learner(): User
    {
        // job_titles.name is unique, so each learner gets a distinct row.
        $suffix   = uniqid();
        $jobTitle = JobTitle::query()->create(['name' => 'Technician '.$suffix, 'name_en' => 'Technician']);

        return User::factory()->create([
            'name'            => 'Sara',
            'job_title_id'    => $jobTitle->id,
            'department_name' => 'Operations',
        ]);
    }

    private function enrol(User $user, ?CourseSection $cohort = null): Course
    {
        $course = $cohort?->course_id
            ? Course::query()->find($cohort->course_id)
            : Course::factory()->create();

        DB::table('users_courses')->insert([
            'user_id'    => $user->id,
            'course_id'  => $course->id,
            'group_id'   => $cohort?->id,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        return $course;
    }

    private function passExam(User $user, Course $course, float $degree = 100, float $max = 105): void
    {
        DB::table('user_exams')->insert([
            'user_id'      => $user->id,
            'course_id'    => $course->id,
            'exam_id'      => CourseExam::factory()->create(['course_id' => $course->id])->id,
            'user_degree'  => $degree,
            'max_score'    => $max,
            'status'       => 'success',
            'submission_status' => 'submitted',
            'submitted_at' => now()->subDay(),
            'created_at'   => now()->subDay(),
            'updated_at'   => now()->subDay(),
        ]);
        // A certificate course is completed by its certificate (D-082, D-086).
        \App\Models\UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    }

    private function url(User $u, string $suffix = ''): string
    {
        return self::BASE.'/admin/learners/'.$u->id.$suffix;
    }

    // ------------------------------------------- translatable titles (D3)

    /**
     * The tables are built with the query builder, which returns a Spatie
     * translatable column as its stored JSON. Factories write plain strings,
     * so the original tests never saw it - these use real bilingual titles.
     */
    public function test_course_and_cohort_names_are_localised_not_raw_json(): void
    {
        $learner = $this->learner();
        $course  = Course::factory()->create(['title' => ['en' => 'Excel Basics', 'ar' => 'أساسيات إكسل']]);
        $cohort  = CourseSection::factory()->create(['course_id' => $course->id, 'name' => ['en' => 'Cohort C', 'ar' => 'المجموعة ج']]);
        $this->enrol($learner, $cohort);
        ['headers' => $headers] = $this->adminToken();

        $en = $this->getJson($this->url($learner, '/courses'), $headers + ['Accept-Language' => 'en'])->assertOk()->json('result.0');
        $ar = $this->getJson($this->url($learner, '/courses'), $headers + ['Accept-Language' => 'ar'])->assertOk()->json('result.0');

        $this->assertSame(['Excel Basics', 'Cohort C'], [$en['course'], $en['cohort']]);
        $this->assertSame(['أساسيات إكسل', 'المجموعة ج'], [$ar['course'], $ar['cohort']]);
    }

    public function test_each_course_row_lists_the_qualifications_it_counts_towards(): void
    {
        $learner = $this->learner();
        $course  = $this->enrol($learner);
        $skill   = \App\Models\QualificationSkill::factory()->create(['name' => ['en' => 'Data Literacy', 'ar' => 'الثقافة البيانية']]);
        DB::table('course_qualification_skills')->insert(['course_id' => $course->id, 'qualification_skill_id' => $skill->id]);
        ['headers' => $headers] = $this->adminToken();

        $row = $this->getJson($this->url($learner, '/courses'), $headers + ['Accept-Language' => 'en'])->assertOk()->json('result.0');

        $this->assertSame([['id' => $skill->id, 'name' => 'Data Literacy']], $row['qualifications']);
    }

    public function test_quiz_and_course_names_in_the_performance_table_are_localised(): void
    {
        $learner = $this->learner();
        // A real bilingual title. CourseFactory's default passes json_encode()
        // output to a translatable attribute, which Spatie stores double-
        // encoded - a shape production never has (B-108).
        $course  = Course::factory()->create(['title' => ['en' => 'Advanced Excel', 'ar' => 'إكسل المتقدم']]);
        DB::table('users_courses')->insert(['user_id' => $learner->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        $exam    = CourseExam::factory()->create(['course_id' => $course->id, 'title' => ['en' => 'Final Quiz', 'ar' => 'الاختبار النهائي']]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'user_degree' => 90, 'max_score' => 100, 'status' => 'success', 'submission_status' => 'submitted',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        ['headers' => $headers] = $this->adminToken();

        $ar = collect($this->getJson($this->url($learner, '/performance'), $headers + ['Accept-Language' => 'ar'])->assertOk()->json('result'))
            ->firstWhere('kind', 'quiz');

        $this->assertSame('الاختبار النهائي', $ar['name']);
        $this->assertSame('إكسل المتقدم', $ar['course']);
    }

    public function test_the_last_active_course_is_a_localised_title(): void
    {
        $learner = $this->learner();
        $course  = Course::factory()->create(['title' => ['en' => 'First Course', 'ar' => 'الكورس الأول']]);
        DB::table('users_courses')->insert(['user_id' => $learner->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        ['headers' => $headers] = $this->adminToken();

        $this->assertSame('First Course', $this->getJson($this->url($learner), $headers + ['Accept-Language' => 'en'])->json('result.profile.last_active_course'));
        $this->assertSame('الكورس الأول', $this->getJson($this->url($learner), $headers + ['Accept-Language' => 'ar'])->json('result.profile.last_active_course'));
    }

    public function test_a_graded_assignment_without_a_maximum_shows_its_score(): void
    {
        $learner    = $this->learner();
        $course     = $this->enrol($learner);
        $assignment = \App\Models\CourseAssignment::factory()->create(['course_id' => $course->id]);
        DB::table('user_course_assignments')->insert([
            'user_id' => $learner->id, 'course_assignment_id' => $assignment->id,
            'score' => 60, 'max_score' => null, 'total_score' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ['headers' => $headers] = $this->adminToken();

        $row = collect($this->getJson($this->url($learner, '/performance'), $headers)->assertOk()->json('result'))->firstWhere('kind', 'assignment');

        $this->assertSame('60', $row['grade_label']);
        $this->assertSame('pass', $row['status']);
    }

    public function test_localized_json_pick_handles_every_stored_shape(): void
    {
        $pick = [\App\Support\LocalizedJson::class, 'pick'];

        $this->assertSame('Hello', $pick('{"en":"Hello","ar":"مرحبا"}', 'en'));
        $this->assertSame('مرحبا', $pick('{"en":"Hello","ar":"مرحبا"}', 'ar'));
        $this->assertSame('Hello', $pick('{"en":"Hello","ar":""}', 'ar'));   // falls back
        $this->assertSame('plain title', $pick('plain title', 'ar'));        // legacy plain text
        $this->assertNull($pick(null, 'en'));
        $this->assertSame('', $pick('', 'en'));
        $this->assertSame('Bonjour', $pick('{"fr":"Bonjour"}', 'en'));       // other locale key
    }

    // -------------------------------------------------------- profile + tiles

    public function test_the_profile_card_returns_the_designed_fields(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $profile = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.profile');

        $this->assertSame('Sara', $profile['name']);
        $this->assertSame('Technician', $profile['job_title']);
        $this->assertSame('Operations', $profile['department']);
        $this->assertArrayHasKey('employee_id', $profile);
        $this->assertArrayHasKey('email', $profile);
        $this->assertArrayHasKey('last_active_course', $profile);
    }

    public function test_the_profile_photo_is_a_loadable_url_not_a_disk_path(): void
    {
        $learner = $this->learner();
        $learner->forceFill(['image' => 'users/photo.webp'])->save();
        ['headers' => $headers] = $this->adminToken();

        $profile = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.profile');

        $this->assertArrayNotHasKey('image', $profile);
        $this->assertStringStartsWith('http', (string) $profile['image_url']);
        $this->assertStringEndsWith('users/photo.webp', (string) $profile['image_url']);
    }

    public function test_a_learner_without_a_photo_has_a_null_image_url(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->assertNull($this->getJson($this->url($learner), $headers)->assertOk()->json('result.profile.image_url'));
    }

    public function test_the_five_summary_tiles_are_present_and_computed(): void
    {
        $learner = $this->learner();

        $passed = $this->enrol($learner);
        $this->passExam($learner, $passed, degree: 100, max: 105);
        $this->enrol($learner);
        $this->enrol($learner);

        ['headers' => $headers] = $this->adminToken();
        $tiles = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.tiles');

        $this->assertSame(1, $tiles['completed_courses']);
        $this->assertSame(2, $tiles['active_courses']);
        $this->assertArrayHasKey('active_course_progress_percent', $tiles);
        $this->assertEqualsWithDelta(100, $tiles['last_quiz']['score'], 0.01);
        $this->assertEqualsWithDelta(105, $tiles['last_quiz']['max'], 0.01);
        $this->assertArrayHasKey('earned_qualifications', $tiles);
    }

    public function test_a_learner_with_no_activity_reports_nulls_not_fake_zeroes(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $tiles = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.tiles');

        $this->assertSame(0, $tiles['completed_courses']);
        $this->assertSame(0, $tiles['active_courses']);
        // Null so the UI renders the design's "—" rather than implying 0%.
        $this->assertNull($tiles['active_course_progress_percent']);
        $this->assertNull($tiles['last_quiz']);
    }

    // ---------------------------------------------------------- courses table

    public function test_the_courses_table_reports_attendance_and_status(): void
    {
        $learner = $this->learner();
        $course  = Course::factory()->create();
        $cohort  = CourseSection::factory()->create(['course_id' => $course->id]);

        DB::table('users_courses')->insert([
            'user_id'    => $learner->id,
            'course_id'  => $course->id,
            'group_id'   => $cohort->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        // Three sessions scheduled, one attended.
        $sessionIds = [];
        for ($i = 0; $i < 3; $i++) {
            $sessionIds[] = DB::table('course_sessions')->insertGetId([
                'course_id'    => $course->id,
                'section_id'   => $cohort->id,
                'title'        => "S{$i}",
                'session_date' => now()->subDays(9 - $i)->toDateString(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        DB::table('attendances')->insert([
            'user_id'    => $learner->id,
            'course_id'  => $course->id,
            'section_id' => $cohort->id,
            'session_id' => $sessionIds[0],
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->getJson($this->url($learner, '/courses'), $headers)->assertOk()->json('result.0');

        $this->assertSame(1, $row['attended']);
        $this->assertSame(2, $row['absent']);
        $this->assertSame(33, $row['progress_percent']);
        // Every session is over and nothing completed the course: it ended, as
        // the Website buckets it (D-086).
        $this->assertSame('ended', $row['status']);
    }

    public function test_a_future_session_is_not_an_absence_and_the_course_is_active(): void
    {
        $learner = $this->learner();
        $course  = Course::factory()->create();
        $cohort  = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('users_courses')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'group_id' => $cohort->id,
            'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
        ]);
        foreach ([-3, -1, 4] as $offset) {
            DB::table('course_sessions')->insert([
                'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => "S{$offset}",
                'session_date' => now()->addDays($offset)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        ['headers' => $headers] = $this->adminToken();
        $row   = $this->getJson($this->url($learner, '/courses'), $headers)->assertOk()->json('result.0');
        $tiles = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.tiles');

        $this->assertSame(2, $row['absent'], 'Only the two past sessions can be missed.');
        $this->assertSame('active', $row['status']);
        $this->assertSame(1, $tiles['active_courses']);
        $this->assertSame(0, $tiles['completed_courses']);
    }

    public function test_an_upcoming_cohort_is_not_an_active_course(): void
    {
        // NEW2B-6229: "Active" is the Website's Current tab, which leaves
        // upcoming cohorts out.
        $learner = $this->learner();
        $course  = Course::factory()->create();
        $cohort  = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('users_courses')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'group_id' => $cohort->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_sessions')->insert([
            'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => 'S1',
            'session_date' => now()->addDays(7)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        ['headers' => $headers] = $this->adminToken();
        $row   = $this->getJson($this->url($learner, '/courses'), $headers)->assertOk()->json('result.0');
        $tiles = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.tiles');

        $this->assertSame('upcoming', $row['status']);
        $this->assertSame(0, $row['absent']);
        $this->assertSame(0, $tiles['active_courses']);
    }

    public function test_an_unsubmitted_or_failed_quiz_is_not_reported_as_passed(): void
    {
        // NEW2B-6232: the status column said 'success' for failed attempts.
        $learner = $this->learner();
        $course  = $this->enrol($learner);
        $failed  = CourseExam::factory()->create(['course_id' => $course->id, 'degree' => 100]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => $failed->id,
            'user_degree' => 30, 'max_score' => 100, 'status' => 'success', 'submission_status' => 'submitted',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $passMark = CourseExam::factory()->create(['course_id' => $course->id, 'degree' => 100, 'pass_score' => 80]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => $passMark->id,
            'user_degree' => 70, 'max_score' => 100, 'status' => 'success', 'submission_status' => 'submitted',
            'submitted_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        ['headers' => $headers] = $this->adminToken();
        $rows = collect($this->getJson($this->url($learner, '/performance'), $headers)->assertOk()->json('result'))
            ->where('kind', 'quiz')->values();

        $this->assertCount(2, $rows);
        $this->assertSame(['failed', 'failed'], $rows->pluck('status')->all());
        $this->assertSame('failed', $this->getJson($this->url($learner), $headers)->json('result.tiles.last_quiz.status'));
    }

    public function test_a_passed_course_is_reported_as_completed(): void
    {
        $learner = $this->learner();
        $course  = $this->enrol($learner);
        $this->passExam($learner, $course);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->getJson($this->url($learner, '/courses'), $headers)->assertOk()->json('result.0');

        $this->assertSame('completed', $row['status']);
    }

    public function test_the_courses_table_is_scoped_to_this_learner(): void
    {
        $mine   = $this->learner();
        $theirs = $this->learner();
        $this->enrol($mine);
        $this->enrol($theirs);
        $this->enrol($theirs);

        ['headers' => $headers] = $this->adminToken();

        $this->assertCount(
            1,
            $this->getJson($this->url($mine, '/courses'), $headers)->assertOk()->json('result'),
        );
    }

    // ------------------------------------------------------ performance table

    public function test_performance_merges_quizzes_and_assignments(): void
    {
        $learner = $this->learner();
        $course  = $this->enrol($learner);
        $this->passExam($learner, $course, degree: 90, max: 100);

        $assignment = CourseAssignment::factory()->create([
            'course_id'  => $course->id,
            'pass_score' => 50,
        ]);
        DB::table('user_course_assignments')->insert([
            'user_id'              => $learner->id,
            'course_assignment_id' => $assignment->id,
            'score'                => 30,
            'max_score'            => 100,
            'submitted_at'         => now()->subHours(2),
            'created_at'           => now()->subHours(2),
            'updated_at'           => now()->subHours(2),
        ]);

        ['headers' => $headers] = $this->adminToken();
        $rows = $this->getJson($this->url($learner, '/performance'), $headers)->assertOk()->json('result');

        $kinds = collect($rows)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['assignment', 'quiz'], $kinds);

        $quiz = collect($rows)->firstWhere('kind', 'quiz');
        $this->assertSame('pass', $quiz['status']);
        $this->assertSame('90/100', $quiz['grade_label']);

        // 30 against a pass_score of 50 is a fail, not merely "graded".
        $assignmentRow = collect($rows)->firstWhere('kind', 'assignment');
        $this->assertSame('failed', $assignmentRow['status']);
    }

    public function test_an_ungraded_assignment_is_needs_review_not_a_zero(): void
    {
        $learner    = $this->learner();
        $course     = $this->enrol($learner);
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id, 'pass_score' => 50]);

        DB::table('user_course_assignments')->insert([
            'user_id'              => $learner->id,
            'course_assignment_id' => $assignment->id,
            'score'                => null,
            'submitted_at'         => now()->subHour(),
            'created_at'           => now()->subHour(),
            'updated_at'           => now()->subHour(),
        ]);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->getJson($this->url($learner, '/performance'), $headers)->assertOk()->json('result.0');

        $this->assertSame('needs_review', $row['status']);
        $this->assertNull($row['grade_label']);
    }

    // ------------------------------------------ search and From / To (NEW2B-6226)

    public function test_search_finds_courses_and_assessments_by_name_in_either_language(): void
    {
        $learner = $this->learner();
        $excel = Course::factory()->create(['title' => ['en' => 'Excel Basics', 'ar' => 'أساسيات إكسل']]);
        $safety = Course::factory()->create(['title' => ['en' => 'Safety', 'ar' => 'السلامة']]);
        foreach ([$excel, $safety] as $c) {
            DB::table('users_courses')->insert(['user_id' => $learner->id, 'course_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $exam = CourseExam::factory()->create(['course_id' => $safety->id, 'title' => ['en' => 'Fire Drill Quiz', 'ar' => 'اختبار الحريق']]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $safety->id, 'exam_id' => $exam->id, 'user_degree' => 5, 'max_score' => 10,
            'status' => 'success', 'submission_status' => 'submitted', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        ['headers' => $h] = $this->adminToken();

        $courses = fn (string $q) => array_column($this->getJson($this->url($learner, '/courses?search='.urlencode($q)), $h + ['Accept-Language' => 'en'])->assertOk()->json('result'), 'course');
        $this->assertSame(['Excel Basics'], $courses('excel'));
        $this->assertSame(['Excel Basics'], $courses('إكسل'));
        $this->assertSame([], $courses('100%'));

        $perf = fn (string $q) => count($this->getJson($this->url($learner, '/performance?search='.urlencode($q)), $h)->assertOk()->json('result'));
        $this->assertSame(1, $perf('fire'));     // the quiz's name
        $this->assertSame(1, $perf('السلامة'));  // its course's name
        $this->assertSame(0, $perf('excel'));
    }

    public function test_from_and_to_filter_cohort_dates_and_last_updates(): void
    {
        $learner = $this->learner();
        $old = CourseSection::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-02-01']);
        $new = CourseSection::factory()->create(['start_date' => '2026-06-01', 'end_date' => '2026-07-01']);
        $this->enrol($learner, $old);
        $course = $this->enrol($learner, $new);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => CourseExam::factory()->create(['course_id' => $course->id])->id,
            'user_degree' => 5, 'max_score' => 10, 'status' => 'success', 'submission_status' => 'submitted',
            'submitted_at' => '2026-06-15 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ['headers' => $h] = $this->adminToken();

        $rows = fn (string $table, string $q) => $this->getJson($this->url($learner, "/$table?$q"), $h)->assertOk()->json('result');
        $this->assertSame([$new->id], array_column($rows('courses', 'from=2026-05-01&to=2026-12-31'), 'cohort_id'));
        $this->assertSame([$old->id], array_column($rows('courses', 'to=2026-03-01'), 'cohort_id'));
        $this->assertCount(1, $rows('performance', 'from=2026-06-15&to=2026-06-15'));
        $this->assertCount(0, $rows('performance', 'from=2026-06-16'));

        $this->getJson($this->url($learner, '/courses?from=2026-05-01&to=2026-04-01'), $h)->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_a_question_based_assignment_shows_its_grade_once_scored(): void
    {
        $learner = $this->learner();
        $course = $this->enrol($learner);
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id, 'pass_score' => 10, 'total_score' => 20]);
        $question = \App\Models\CourseAssignmentQuestion::create(['course_assignment_id' => $assignment->id, 'position' => 0, 'type' => 'open', 'score' => 20, 'question_en' => 'Why?']);
        $submission = DB::table('user_course_assignments')->insertGetId([
            'user_id' => $learner->id, 'course_assignment_id' => $assignment->id, 'score' => null, 'total_score' => 0, 'max_score' => 20,
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $answer = DB::table('user_course_assignment_answers')->insertGetId([
            'user_course_assignment_id' => $submission, 'course_assignment_question_id' => $question->id, 'answer' => json_encode(['value' => 'x']),
            'awarded_score' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ['headers' => $h] = $this->adminToken();
        $row = fn () => collect($this->getJson($this->url($learner, '/performance'), $h)->assertOk()->json('result'))->firstWhere('kind', 'assignment');

        // Waiting for the instructor.
        $this->assertSame('needs_review', $row()['status']);

        // Scored 16 of 20 (kept in total_score, not the legacy `score`).
        DB::table('user_course_assignment_answers')->where('id', $answer)->update(['awarded_score' => 16]);
        DB::table('user_course_assignments')->where('id', $submission)->update(['total_score' => 16]);
        $this->assertSame('pass', $row()['status']);
        $this->assertSame('16/20', $row()['grade_label']);

        // An opened attempt that was never sent is not a result.
        DB::table('user_course_assignments')->where('id', $submission)->update(['submitted_at' => null]);
        $this->assertNull($row());
    }

    // -------------------------------------------------- pagination / auth /404

    public function test_both_tables_bound_per_page(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        foreach (['/courses', '/performance'] as $suffix) {
            $this->getJson($this->url($learner, $suffix).'?per_page=100000', $headers)->assertStatus(422);
            $this->getJson($this->url($learner, $suffix).'?per_page=0', $headers)->assertStatus(422);
            $this->getJson($this->url($learner, $suffix).'?per_page=5', $headers)->assertOk();
        }
    }

    public function test_the_tables_page_independently(): void
    {
        $learner = $this->learner();
        for ($i = 0; $i < 4; $i++) {
            $this->enrol($learner);
        }

        ['headers' => $headers] = $this->adminToken();

        $this->assertCount(
            2,
            $this->getJson($this->url($learner, '/courses').'?per_page=2', $headers)->assertOk()->json('result'),
        );
    }

    public function test_an_admin_without_view_users_is_refused(): void
    {
        $learner = $this->learner();

        $role = Role::findOrCreate('learner-detail-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        foreach (['', '/courses', '/performance'] as $suffix) {
            $this->getJson($this->url($learner, $suffix), $headers)->assertStatus(403);
        }
    }

    public function test_a_learner_cannot_read_another_learners_profile(): void
    {
        $target = $this->learner();
        ['headers' => $headers] = $this->userToken();

        $this->getJson($this->url($target), $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson($this->url($this->learner()))->assertStatus(401);
    }

    public function test_an_unknown_learner_is_404(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/learners/999999', $headers)->assertStatus(404);
    }
}
