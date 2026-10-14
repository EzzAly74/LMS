<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * Course scope (D-074): an instructor signed in to the Dashboard sees and
 * changes only the courses they teach, those courses' records and learners,
 * and analytics built from them; anything else is a 404, and organisation-
 * wide sections are refused.
 */
class CourseScopeTest extends ApiTestCase
{
    private Course $mine;
    private Course $theirs;
    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);
        $other = Instructor::query()->create(['name' => ['en' => 'Omar', 'ar' => 'عمر'], 'email' => 'omar@academy.test']);

        $this->mine = Course::factory()->create();
        $this->theirs = Course::factory()->create();
        $this->mine->instructors()->attach($this->instructor->id);
        $this->theirs->instructors()->attach($other->id);
    }

    /** A Dashboard account for the instructor, through a role of the given scope. */
    private function account(string $scope = 'assigned', ?int $instructorId = -1, array $permissions = []): array
    {
        $role = Role::findOrCreate('scope-'.uniqid(), 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => $scope]);
        $role->givePermissionTo(array_map(fn ($n) => Permission::findOrCreate($n, 'admin'), $permissions ?: [
            'view-dashboard', 'view-courses', 'edit-courses', 'view-quizzes', 'view-learners',
            'view-reports', 'view-job-titles', 'view-evaluations',
        ]));

        $admin = Admin::factory()->create();
        $admin->forceFill(['instructor_id' => $instructorId === -1 ? $this->instructor->id : $instructorId])->save();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    public function test_the_course_list_holds_only_my_courses(): void
    {
        ['headers' => $h] = $this->account();

        $ids = $this->ids($this->getJson(self::BASE.'/courses?per_page=50', $h)->assertOk()->json('result'));

        $this->assertSame([$this->mine->id], $ids);
    }

    public function test_another_instructors_course_is_not_found_to_read_or_change(): void
    {
        ['headers' => $h] = $this->account();

        $this->getJson(self::BASE."/courses/{$this->mine->id}", $h)->assertOk();
        $this->getJson(self::BASE."/courses/{$this->theirs->id}", $h)->assertNotFound();
        $this->getJson(self::BASE."/courses/{$this->theirs->id}/sections", $h)->assertNotFound();
        $this->putJson(self::BASE."/courses/{$this->theirs->id}", [], $h)->assertNotFound();
        $this->getJson(self::BASE."/courses/{$this->theirs->id}/enrollments", $h)->assertNotFound();
    }

    public function test_records_of_another_course_are_not_found(): void
    {
        ['headers' => $h] = $this->account();
        $quiz = CourseExam::factory()->create(['course_id' => $this->theirs->id]);
        $myQuiz = CourseExam::factory()->create(['course_id' => $this->mine->id]);
        // The quiz list shows quizzes that have questions.
        foreach ([$quiz, $myQuiz] as $exam) {
            DB::table('course_exam_questions')->insert(['course_exam_id' => $exam->id, 'question' => json_encode(['en' => 'Q?', 'ar' => 'س؟']), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->getJson(self::BASE."/admin/quizzes/{$quiz->id}", $h)->assertNotFound();
        $this->getJson(self::BASE."/admin/quizzes/{$myQuiz->id}", $h)->assertOk();

        $listed = $this->ids($this->getJson(self::BASE.'/admin/quizzes?per_page=50', $h)->assertOk()->json('result'));
        $this->assertContains($myQuiz->id, $listed);
        $this->assertNotContains($quiz->id, $listed);
    }

    /** NEW2B-6286: an instructor sees the assignments and answers of their own courses only. */
    public function test_assignments_and_submissions_of_another_course_are_not_found(): void
    {
        ['headers' => $h] = $this->account('assigned', -1, ['view-dashboard', 'view-courses', 'view-assignments', 'edit-assignments', 'delete-assignments']);
        $mine = \App\Models\CourseAssignment::factory()->create(['course_id' => $this->mine->id]);
        $theirs = \App\Models\CourseAssignment::factory()->create(['course_id' => $this->theirs->id]);
        $learner = User::factory()->create();
        $submissionIds = [];
        foreach ([$mine, $theirs] as $assignment) {
            $submissionIds[] = DB::table('user_course_assignments')->insertGetId([
                'user_id' => $learner->id, 'course_assignment_id' => $assignment->id,
                'score' => 60, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        [$mySubmission, $theirSubmission] = $submissionIds;

        $listed = $this->ids($this->getJson(self::BASE.'/admin/assignments?per_page=50', $h)->assertOk()->json('result'));
        $this->assertSame([$mine->id], $listed);
        $this->getJson(self::BASE."/admin/assignments/{$mine->id}", $h)->assertOk();
        $this->getJson(self::BASE."/admin/assignments/{$theirs->id}", $h)->assertNotFound();
        $this->putJson(self::BASE."/admin/assignments/{$theirs->id}", [], $h)->assertNotFound();
        $this->deleteJson(self::BASE."/admin/assignments/{$theirs->id}", [], $h)->assertNotFound();
        $this->assertDatabaseHas('course_assignments', ['id' => $theirs->id]);

        $rows = $this->ids($this->getJson(self::BASE.'/admin/assignments/submissions?per_page=50', $h)->assertOk()->json('result'));
        $this->assertSame([$mySubmission], $rows);
        $this->getJson(self::BASE."/admin/assignments/submissions/{$mySubmission}", $h)->assertOk();
        $this->getJson(self::BASE."/admin/assignments/submissions/{$theirSubmission}", $h)->assertNotFound();

        $minimal = $this->ids($this->getJson(self::BASE.'/admin/assignments/list', $h)->assertOk()->json('result'));
        $this->assertSame([$mine->id], $minimal);
        $this->getJson(self::BASE."/admin/assignments/cohorts?course_id={$this->theirs->id}", $h)->assertNotFound();
    }

    /** NEW2B-6286: a course named in the body is checked like one in the URL. */
    public function test_a_quiz_or_assignment_cannot_be_written_into_another_instructors_course(): void
    {
        ['headers' => $h] = $this->account('assigned', -1, ['view-dashboard', 'view-courses', 'view-quizzes', 'create-quizzes', 'view-assignments', 'create-assignments']);
        $question = ['type' => 'mcq', 'score' => 5, 'question_en' => 'Pick', 'question_ar' => 'اختر',
            'options_en' => ['a', 'b'], 'options_ar' => ['أ', 'ب'], 'correct_answer_en' => 'a', 'correct_answer_ar' => 'أ'];
        $body = fn (Course $c) => ['course_id' => $c->id, 'title' => 'Check', 'title_ar' => 'فحص', 'type' => 'mid',
            'cohort_scope' => 'all', 'status' => 'active', 'questions' => [$question]];

        $this->postJson(self::BASE.'/admin/quizzes', $body($this->theirs), $h)->assertNotFound();
        $this->postJson(self::BASE.'/admin/assignments', $body($this->theirs), $h)->assertNotFound();
        $this->assertDatabaseMissing('course_exams', ['course_id' => $this->theirs->id]);
        $this->assertDatabaseMissing('course_assignments', ['course_id' => $this->theirs->id]);

        $this->postJson(self::BASE.'/admin/quizzes', $body($this->mine), $h)->assertSuccessful();
        $this->postJson(self::BASE.'/admin/assignments', $body($this->mine), $h)->assertSuccessful();
    }

    /** NEW2B-5804: an instructor's new course is taught by them; an edit cannot add someone else. */
    public function test_an_instructor_creates_courses_for_themselves_only(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        ['model' => $admin, 'headers' => $h] = $this->account('assigned', -1, ['view-dashboard', 'view-courses', 'create-courses', 'edit-courses']);
        $other = Instructor::query()->where('id', '!=', $this->instructor->id)->value('id');
        $course = fn (array $instructors) => [
            'title' => ['en' => 'Mine', 'ar' => 'دورتي'], 'description' => ['en' => 'About', 'ar' => 'عن'],
            'course_type' => 'online', 'level' => 'beginner', 'certificate_rule' => 'general',
            'instructors' => $instructors, 'image' => \Illuminate\Http\UploadedFile::fake()->image('c.png', 400, 300),
        ];

        $this->post(self::BASE.'/courses', $course([$other]), $h + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('instructors');
        $this->post(self::BASE.'/courses', $course([$this->instructor->id, $other]), $h + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('instructors');
        $this->post(self::BASE.'/courses', $course([$this->instructor->id]), $h + ['Accept' => 'application/json'])
            ->assertSuccessful();

        $edit = ['_method' => 'PUT', 'title' => ['en' => 'Renamed', 'ar' => 'معدلة'], 'course_type' => $this->mine->course_type ?? 'online', 'level' => 'beginner'];
        $this->post(self::BASE."/courses/{$this->mine->id}", $edit + ['instructors' => [$this->instructor->id, $other]], $h + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('instructors');
        $this->post(self::BASE."/courses/{$this->mine->id}", $edit + ['instructors' => [$this->instructor->id]], $h + ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame([$this->instructor->id], $this->mine->instructors()->pluck('instructors.id')->map(fn ($id) => (int) $id)->all());
    }

    /** Unscoped admins still pick any instructor. */
    public function test_an_administrator_picks_any_instructor(): void
    {
        ['headers' => $h] = $this->adminToken();
        $other = Instructor::query()->where('id', '!=', $this->instructor->id)->value('id');
        $edit = ['_method' => 'PUT', 'title' => ['en' => 'Renamed', 'ar' => 'معدلة'], 'course_type' => $this->mine->course_type ?? 'online', 'level' => 'beginner'];

        $this->post(self::BASE."/courses/{$this->mine->id}", $edit + ['instructors' => [$other]], $h + ['Accept' => 'application/json'])->assertOk();
    }

    public function test_the_dashboard_counts_only_my_courses_and_learners(): void
    {
        $learner = User::factory()->create();
        $stranger = User::factory()->create();
        DB::table('users_courses')->insert([
            ['user_id' => $learner->id, 'course_id' => $this->mine->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $stranger->id, 'course_id' => $this->theirs->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        ['headers' => $h] = $this->account();

        $result = $this->getJson(self::BASE.'/dashboard', $h)->assertOk()->json('result');

        $this->assertSame('assigned', $result['course_scope']);
        $this->assertSame(1, (int) $result['statistics']['courses']);
        $this->assertSame(1, (int) $result['statistics']['users']);
        $this->assertNull($result['statistics']['instructors']);
        $this->assertSame([$this->mine->id], array_map('intval', array_column($result['top_courses'], 'id')));
    }

    public function test_learners_are_those_of_my_courses_only(): void
    {
        $learner = User::factory()->create();
        $stranger = User::factory()->create();
        DB::table('users_courses')->insert([
            ['user_id' => $learner->id, 'course_id' => $this->mine->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $stranger->id, 'course_id' => $this->theirs->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        ['headers' => $h] = $this->account();

        $ids = $this->ids($this->getJson(self::BASE.'/admin/learners?per_page=50', $h)->assertOk()->json('result'));
        $this->assertSame([$learner->id], $ids);

        $this->getJson(self::BASE."/admin/learners/{$learner->id}", $h)->assertOk();
        $this->getJson(self::BASE."/admin/learners/{$stranger->id}", $h)->assertNotFound();
    }

    public function test_organisation_wide_sections_are_refused_even_with_the_permission(): void
    {
        ['headers' => $h] = $this->account();

        $this->getJson(self::BASE.'/admin/job-titles', $h)->assertForbidden();
        $this->getJson(self::BASE.'/reports/completion', $h)->assertForbidden();
    }

    public function test_a_scoped_account_without_an_instructor_link_sees_no_courses(): void
    {
        ['headers' => $h] = $this->account('assigned', null);

        $this->assertSame([], $this->getJson(self::BASE.'/courses', $h)->assertOk()->json('result'));
        $this->getJson(self::BASE."/courses/{$this->mine->id}", $h)->assertNotFound();
    }

    public function test_one_role_with_every_course_widens_the_account(): void
    {
        ['model' => $admin, 'headers' => $h] = $this->account();
        $wide = Role::findOrCreate('wide-'.uniqid(), 'admin');
        $wide->givePermissionTo('view-courses');
        $admin->assignRole($wide);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ids = $this->ids($this->getJson(self::BASE.'/courses?per_page=50', $h)->assertOk()->json('result'));
        $this->assertEqualsCanonicalizing([$this->mine->id, $this->theirs->id], $ids);
    }

    public function test_the_seeded_instructor_role_is_limited_to_assigned_courses(): void
    {
        $this->assertSame('assigned', DB::table('roles')->where('name', 'instructor')->where('guard_name', 'admin')->value('course_scope'));
        $this->assertSame('all', DB::table('roles')->where('name', 'admin')->where('guard_name', 'admin')->value('course_scope'));
    }

    public function test_learners_and_unscoped_admins_are_unaffected(): void
    {
        ['headers' => $admin] = $this->adminToken();
        ['headers' => $learner] = $this->userToken();

        $this->assertCount(2, $this->getJson(self::BASE.'/courses?per_page=50', $admin)->assertOk()->json('result'));
        $this->getJson(self::BASE."/courses/{$this->theirs->id}", $learner)->assertOk();
    }
}
