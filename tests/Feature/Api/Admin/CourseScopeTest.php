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
