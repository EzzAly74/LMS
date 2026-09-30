<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseExam;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use App\Models\UserExam;
use App\Models\UsersCourse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * D2 - Course Details tabs (Figma 2266:128868).
 *
 * Learners tab (2266:129915) and cohort learners modal (2276:133999):
 * GET courses/{course}/enrollments. Quizzes / Assignments tabs (2295:53311,
 * 2294:51575): the admin submission lists with the learner's cohort. Header
 * and tab counts (2266:128869): GET courses/{course}.
 */
class CourseDetailTabsTest extends ApiTestCase
{
    private Course $course;

    private CourseSection $cohortA;

    private CourseSection $cohortB;

    /** @var list<CourseLecture> */
    private array $lectures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->course  = Course::factory()->create();
        $this->cohortA = CourseSection::factory()->create(['course_id' => $this->course->id, 'name' => ['en' => 'Cohort A', 'ar' => 'الدفعة أ']]);
        $this->cohortB = CourseSection::factory()->create(['course_id' => $this->course->id, 'name' => ['en' => 'Cohort B', 'ar' => 'الدفعة ب']]);

        for ($i = 0; $i < 4; $i++) {
            $this->lectures[] = CourseLecture::factory()->create(['course_id' => $this->course->id, 'section_id' => $this->cohortA->id]);
        }
    }

    /** Enrol a learner in a cohort and mark `$done` of the 4 lectures complete. */
    private function learner(CourseSection $cohort, int $done, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        UsersCourse::query()->create(['user_id' => $user->id, 'course_id' => $this->course->id, 'group_id' => $cohort->id]);

        foreach (array_slice($this->lectures, 0, $done) as $lecture) {
            DB::table('user_lecture_progress')->insert([
                'user_id' => $user->id, 'lecture_id' => $lecture->id, 'progress' => 100,
                'completed' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function learners(array $query = [], ?array $headers = null)
    {
        $url = self::BASE."/courses/{$this->course->id}/enrollments".($query ? '?'.http_build_query($query) : '');

        return $this->getJson($url, $headers ?? $this->adminToken()['headers']);
    }

    /** A quiz of this course with one question (the submissions list only shows quizzes with questions). */
    private function quiz(): CourseExam
    {
        $quiz = CourseExam::factory()->create(['course_id' => $this->course->id, 'section_id' => $this->cohortA->id]);
        DB::table('course_exam_questions')->insert([
            'course_exam_id' => $quiz->id, 'position' => 1, 'type' => 'mcq', 'score' => 10,
            'question_en' => 'Q', 'question_ar' => 'س', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $quiz;
    }

    private function adminWith(string ...$permissions): array
    {
        $admin = Admin::factory()->create();
        $role  = Role::findOrCreate('d2-'.uniqid(), 'admin');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'admin'));
        }
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->adminToken($admin)['headers'];
    }

    // ── Learners tab ───────────────────────────────────────────────────────

    public function test_guests_learners_and_admins_without_view_courses_are_refused(): void
    {
        $this->learners([], [])->assertStatus(401);
        $this->learners([], $this->userToken()['headers'])->assertStatus(403);
        $this->learners([], $this->adminWith('view-learners'))->assertStatus(403);
    }

    public function test_rows_carry_progress_status_cohort_and_account_state(): void
    {
        $this->learner($this->cohortA, 4, ['name_en' => 'Ali Done']);
        $this->learner($this->cohortA, 2, ['name_en' => 'Omar Half']);
        $this->learner($this->cohortB, 0, ['name_en' => 'Sara New', 'status' => 'inactive']);

        $rows = collect($this->learners()->assertOk()->json('result'))->keyBy('user.name');

        $this->assertSame(['progress' => 100, 'status' => 'completed'], ['progress' => $rows['Ali Done']['progress'], 'status' => $rows['Ali Done']['status']]);
        $this->assertSame(50, $rows['Omar Half']['progress']);
        $this->assertSame('in_progress', $rows['Omar Half']['status']);
        $this->assertSame('not_started', $rows['Sara New']['status']);
        $this->assertSame('Cohort B', $rows['Sara New']['cohort']['name']);
        $this->assertFalse($rows['Sara New']['user']['active']);
        $this->assertTrue($rows['Ali Done']['user']['active']);
        // A resource, not the raw model: no pivot or timestamps leak.
        $this->assertArrayNotHasKey('group_id', $rows['Ali Done']);
    }

    public function test_status_cohort_and_search_filter_in_the_database(): void
    {
        $this->learner($this->cohortA, 4, ['name_en' => 'Ali Done']);
        $this->learner($this->cohortA, 2, ['name_en' => 'Omar Half', 'machine_code' => 'EMP-77']);
        $this->learner($this->cohortB, 1, ['name_en' => 'Sara Started']);
        $this->learner($this->cohortB, 0, ['name_en' => 'Khalid New']);

        $names = fn ($res) => collect($res->assertOk()->json('result'))->pluck('user.name')->sort()->values()->all();

        $this->assertSame(['Omar Half', 'Sara Started'], $names($this->learners(['status' => 'in_progress'])));
        $this->assertSame(['Ali Done'], $names($this->learners(['status' => 'completed'])));
        $this->assertSame(['Khalid New'], $names($this->learners(['status' => 'not_started'])));
        $this->assertSame(['Khalid New', 'Sara Started'], $names($this->learners(['group_id' => $this->cohortB->id])));
        $this->assertSame(['Omar Half'], $names($this->learners(['search' => 'EMP-77'])));
        $this->assertSame(['Omar Half'], $names($this->learners(['search' => 'omar', 'status' => 'in_progress'])));
        // LIKE wildcards are literal.
        $this->assertSame([], $names($this->learners(['search' => '%'])));

        $this->assertSame(4, $this->learners(['per_page' => 2])->json('meta.total'));
        $this->assertSame(2, $this->learners(['per_page' => 2])->json('meta.last_page'));
    }

    public function test_invalid_filters_and_another_courses_cohort_are_rejected(): void
    {
        $foreign = CourseSection::factory()->create();

        $this->learners(['group_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('group_id');
        $this->learners(['status' => 'finished'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->learners(['per_page' => 500])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->learners(['search' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('search');
    }

    public function test_a_course_without_lectures_reports_everyone_not_started(): void
    {
        $this->course->lectures()->delete();
        $this->learner($this->cohortA, 0);

        $this->assertSame('not_started', $this->learners()->assertOk()->json('result.0.status'));
        $this->assertSame(1, $this->learners(['status' => 'not_started'])->json('meta.total'));
    }

    // ── Header and tab counts ──────────────────────────────────────────────

    public function test_course_detail_reports_in_progress_and_tab_counts(): void
    {
        $this->learner($this->cohortA, 4);
        $a = $this->learner($this->cohortA, 2);
        $this->learner($this->cohortB, 1);
        $this->learner($this->cohortB, 0);

        UserExam::factory()->create(['user_id' => $a->id, 'course_id' => $this->course->id, 'exam_id' => $this->quiz()->id]);
        // A quiz without questions is not listed, so it is not counted either.
        UserExam::factory()->create(['user_id' => $a->id, 'course_id' => $this->course->id,
            'exam_id' => CourseExam::factory()->create(['course_id' => $this->course->id])->id]);

        $assignment = CourseAssignment::factory()->create(['course_id' => $this->course->id]);
        DB::table('user_course_assignments')->insert(['user_id' => $a->id, 'course_assignment_id' => $assignment->id,
            'created_at' => now(), 'updated_at' => now()]);

        $result = $this->getJson(self::BASE."/courses/{$this->course->id}", $this->adminToken()['headers'])
            ->assertOk()->json('result');

        $this->assertSame(4, $result['enrolled_count']);
        $this->assertSame(2, $result['in_progress_count']);
        $this->assertSame(4, $result['modules_count']);
        $this->assertSame(1, $result['quiz_submissions_count']);
        $this->assertSame(1, $result['assignment_submissions_count']);
    }

    // ── Quizzes / Assignments tabs ─────────────────────────────────────────

    public function test_quiz_submissions_carry_the_learners_cohort_and_filter_by_it(): void
    {
        $quiz = $this->quiz();
        $inA  = $this->learner($this->cohortA, 0);
        $inB  = $this->learner($this->cohortB, 0);
        UserExam::factory()->create(['user_id' => $inA->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id]);
        UserExam::factory()->create(['user_id' => $inB->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id]);

        $headers = $this->adminToken()['headers'];
        $url     = self::BASE.'/admin/quizzes/submissions?course_id='.$this->course->id;

        $rows = collect($this->getJson($url, $headers)->assertOk()->json('result'))->keyBy('user.id');
        $this->assertSame('Cohort A', $rows[$inA->id]['learner_cohort']['name']);
        $this->assertSame('Cohort B', $rows[$inB->id]['learner_cohort']['name']);

        $filtered = $this->getJson($url.'&section_id='.$this->cohortB->id, $headers)->assertOk()->json('result');
        $this->assertSame([$inB->id], array_column(array_column($filtered, 'user'), 'id'));

        // Another course's cohort, or a cohort without its course, is a 422.
        $this->getJson($url.'&section_id='.CourseSection::factory()->create()->id, $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/quizzes/submissions?section_id='.$this->cohortA->id, $headers)->assertStatus(422);
    }

    public function test_assignment_submissions_carry_the_learners_cohort_and_filter_by_it(): void
    {
        $assignment = CourseAssignment::factory()->create(['course_id' => $this->course->id]);
        $inA        = $this->learner($this->cohortA, 0);
        $inB        = $this->learner($this->cohortB, 0);
        foreach ([$inA, $inB] as $user) {
            DB::table('user_course_assignments')->insert(['user_id' => $user->id, 'course_assignment_id' => $assignment->id,
                'created_at' => now(), 'updated_at' => now()]);
        }

        $headers = $this->adminToken()['headers'];
        $url     = self::BASE.'/admin/assignments/submissions?course_id='.$this->course->id;

        $rows = collect($this->getJson($url, $headers)->assertOk()->json('result'))->keyBy('user.id');
        $this->assertSame('Cohort A', $rows[$inA->id]['learner_cohort']['name']);

        $filtered = $this->getJson($url.'&section_id='.$this->cohortA->id, $headers)->assertOk()->json('result');
        $this->assertSame([$inA->id], array_column(array_column($filtered, 'user'), 'id'));
    }

    public function test_rows_say_whether_the_score_passes_the_items_own_pass_score(): void
    {
        $quiz = $this->quiz();
        $quiz->update(['pass_score' => 50]);
        $pass = $this->learner($this->cohortA, 0);
        $fail = $this->learner($this->cohortA, 0);
        $wait = $this->learner($this->cohortA, 0);
        UserExam::factory()->create(['user_id' => $pass->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id, 'total_score' => 60, 'max_score' => 100]);
        UserExam::factory()->create(['user_id' => $fail->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id, 'total_score' => 40, 'max_score' => 100]);
        UserExam::factory()->create(['user_id' => $wait->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id, 'total_score' => null, 'max_score' => 100]);

        $rows = collect($this->getJson(self::BASE.'/admin/quizzes/submissions?course_id='.$this->course->id, $this->adminToken()['headers'])
            ->assertOk()->json('result'))->keyBy('user.id');

        $this->assertTrue($rows[$pass->id]['passed']);
        $this->assertFalse($rows[$fail->id]['passed']);
        $this->assertNull($rows[$wait->id]['passed']);
    }

    public function test_filter_options_list_only_values_in_this_courses_submissions(): void
    {
        $creator = User::factory()->create(['name_en' => 'Nora Instructor']);
        $quiz    = $this->quiz();
        $quiz->update(['created_by' => $creator->id]);
        $unused  = $this->quiz(); // no submissions: not offered
        $learner = $this->learner($this->cohortA, 0, ['name_en' => 'Layla Learner']);
        UserExam::factory()->create(['user_id' => $learner->id, 'course_id' => $this->course->id, 'exam_id' => $quiz->id]);

        // Another course's submission must not appear.
        $elsewhere = UserExam::factory()->create();

        $headers = $this->adminToken()['headers'];
        $options = $this->getJson(self::BASE.'/admin/quizzes/submissions/filter-options?course_id='.$this->course->id, $headers)
            ->assertOk()->json('result');

        $this->assertSame([$learner->id], array_column($options['learners'], 'id'));
        $this->assertSame('Layla Learner', $options['learners'][0]['name']);
        $this->assertSame([$creator->id], array_column($options['instructors'], 'id'));
        $this->assertSame([$quiz->id], array_column($options['items'], 'id'));
        $this->assertNotContains($unused->id, array_column($options['items'], 'id'));
        $this->assertNotContains($elsewhere->user_id, array_column($options['learners'], 'id'));

        // Without a course: every course's (the Quizzes / Assignments list pages).
        $all = $this->getJson(self::BASE.'/admin/quizzes/submissions/filter-options', $headers)->assertOk()->json('result');
        $this->assertContains($learner->id, array_column($all['learners'], 'id'));
        $this->assertContains($quiz->id, array_column($all['items'], 'id'));
        $this->getJson(self::BASE.'/admin/assignments/submissions/filter-options', $headers)->assertOk()->assertJsonStructure(['result' => ['learners', 'instructors', 'items']]);
        $this->getJson(self::BASE.'/admin/quizzes/submissions/filter-options?course_id=abc', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/quizzes/submissions/filter-options', $this->adminWith('view-courses'))->assertStatus(403);
        $this->getJson(self::BASE.'/admin/assignments/submissions/filter-options?course_id=999999', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/quizzes/submissions/filter-options?course_id='.$this->course->id, $this->adminWith('view-courses'))
            ->assertStatus(403);
    }

    public function test_assignment_titles_follow_the_request_language(): void
    {
        $assignment = CourseAssignment::factory()->create(['course_id' => $this->course->id, 'title' => 'Practical', 'title_ar' => 'تطبيق عملي']);
        $learner    = $this->learner($this->cohortA, 0);
        DB::table('user_course_assignments')->insert(['user_id' => $learner->id, 'course_assignment_id' => $assignment->id,
            'created_at' => now(), 'updated_at' => now()]);

        $headers = $this->adminToken()['headers'];
        $url     = self::BASE.'/admin/assignments/submissions?course_id='.$this->course->id;

        $this->assertSame('Practical', $this->getJson($url, $headers + ['Accept-Language' => 'en'])->json('result.0.assignment_title'));
        $this->assertSame('تطبيق عملي', $this->getJson($url, $headers + ['Accept-Language' => 'ar'])->json('result.0.assignment_title'));
        $this->assertSame('تطبيق عملي', $this->getJson(self::BASE.'/admin/assignments/submissions/filter-options?course_id='.$this->course->id,
            $headers + ['Accept-Language' => 'ar'])->json('result.items.0.name'));
    }

    public function test_submission_lists_bound_the_page_size(): void
    {
        $this->assertSame(200, $this->getJson(self::BASE.'/admin/quizzes/submissions?per_page=100000', $this->adminToken()['headers'])
            ->assertOk()->json('meta.per_page'));
    }

    public function test_submission_lists_need_their_permission(): void
    {
        $headers = $this->adminWith('view-courses');

        $this->getJson(self::BASE.'/admin/quizzes/submissions?course_id='.$this->course->id, $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/admin/assignments/submissions?course_id='.$this->course->id, $headers)->assertStatus(403);
    }
}
