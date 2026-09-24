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
            'status'       => 'passed',
            'submitted_at' => now()->subDay(),
            'created_at'   => now()->subDay(),
            'updated_at'   => now()->subDay(),
        ]);
    }

    private function url(User $u, string $suffix = ''): string
    {
        return self::BASE.'/admin/learners/'.$u->id.$suffix;
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
        $this->assertSame('active', $row['status']);
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
