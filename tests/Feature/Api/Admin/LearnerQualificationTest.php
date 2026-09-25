<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * D-045 — granting a qualification directly to a learner.
 *
 * Figma 2066:100876 (New Qualification modal's learner picker) and the
 * "Assign Qualification" bulk action on the learners list. Neither had a
 * backend: the only route to holding a qualification was indirect, via a job
 * title requiring it and the learner completing the courses that grant it.
 *
 * The semantics are ADDITIVE (the human's answer, 1.7). A learner holds a
 * qualification by either route. The important negative case is that a grant
 * must NOT fabricate course completions — an external certificate means the
 * qualification is held, not that the courses were studied.
 */
class LearnerQualificationTest extends ApiTestCase
{
    private JobTitle $jobTitle;

    private QualificationSkill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobTitle = JobTitle::query()->create(['name' => 'Technician '.uniqid()]);
        $this->skill    = QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'لحام']]);
        $this->jobTitle->qualificationSkills()->attach($this->skill->id);
    }

    private function learner(): User
    {
        return User::factory()->create(['job_title_id' => $this->jobTitle->id]);
    }

    private function url(User $learner): string
    {
        return self::BASE.'/admin/learners/'.$learner->id.'/qualifications';
    }

    private function rosterRow(User $learner, array $headers): array
    {
        $rows = $this->getJson(
            self::BASE.'/admin/job-titles/'.$this->jobTitle->id.'/learners',
            $headers,
        )->assertOk()->json('result');

        return collect($rows)->firstWhere('id', $learner->id) ?? [];
    }

    /** A course granting the skill, optionally completed by $user. */
    private function courseGranting(?User $user = null, bool $completed = false): Course
    {
        $course = Course::factory()->create();

        DB::table('course_qualification_skills')->insert([
            'course_id'              => $course->id,
            'qualification_skill_id' => $this->skill->id,
        ]);

        if ($user !== null) {
            DB::table('users_courses')->insert([
                'user_id'    => $user->id,
                'course_id'  => $course->id,
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(7),
            ]);

            if ($completed) {
                $exam = CourseExam::factory()->create(['course_id' => $course->id]);
                DB::table('user_exams')->insert([
                    'user_id'      => $user->id,
                    'course_id'    => $course->id,
                    'exam_id'      => $exam->id,
                    'status'       => 'passed',
                    'submitted_at' => now()->subDays(2),
                    'created_at'   => now()->subDays(2),
                    'updated_at'   => now()->subDays(2),
                ]);
            }
        }

        return $course;
    }

    // ------------------------------------------------------------------ grant

    public function test_an_admin_can_grant_a_qualification_to_a_learner(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $result = $this->postJson($this->url($learner), [
            'qualification_skill_ids' => [$this->skill->id],
            'note'                    => 'Holds an external certificate',
        ], $headers)->assertCreated()->json('result');

        $this->assertSame(1, $result['granted']);
        $this->assertSame(0, $result['already_held']);
        $this->assertTrue($learner->qualificationSkills()->where('qualification_skills.id', $this->skill->id)->exists());
    }

    public function test_a_grant_records_who_made_it_and_why(): void
    {
        $learner = $this->learner();
        ['headers' => $headers, 'model' => $admin] = $this->adminToken();

        $this->postJson($this->url($learner), [
            'qualification_skill_ids' => [$this->skill->id],
            'note'                    => 'Prior learning recognised',
        ], $headers)->assertCreated();

        $row = DB::table('user_qualification_skill')->where('user_id', $learner->id)->first();

        // This path bypasses the normal evidence trail, so an unattributed
        // grant would leave a compliance report nobody can question.
        $this->assertSame($admin->id, (int) $row->assigned_by);
        $this->assertNotNull($row->assigned_at);
        $this->assertSame('Prior learning recognised', $row->note);
    }

    public function test_granting_the_same_qualification_twice_does_not_duplicate_it(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();

        $second = $this->postJson($this->url($learner), [
            'qualification_skill_ids' => [$this->skill->id],
            'note'                    => 'Re-issued',
        ], $headers)->assertCreated()->json('result');

        $this->assertSame(0, $second['granted']);
        $this->assertSame(1, $second['already_held']);
        $this->assertSame(1, DB::table('user_qualification_skill')->where('user_id', $learner->id)->count());

        // Re-granting with a new note is a new decision and is recorded.
        $this->assertSame('Re-issued', DB::table('user_qualification_skill')
            ->where('user_id', $learner->id)->value('note'));
    }

    public function test_an_unknown_qualification_id_is_a_422_not_a_silent_drop(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [999999]], $headers)
            ->assertStatus(422);

        $this->assertSame(0, DB::table('user_qualification_skill')->count());
    }

    public function test_an_empty_list_is_rejected(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => []], $headers)
            ->assertStatus(422);
    }

    // ------------------------------------------------- the additive semantics

    public function test_a_granted_qualification_counts_as_earned(): void
    {
        $learner = $this->learner();
        $this->courseGranting($learner, completed: false);

        ['headers' => $headers] = $this->adminToken();

        // Before the grant: enrolled but nothing finished.
        $before = $this->rosterRow($learner, $headers);
        $this->assertSame(0, $before['qualifications_completed']);

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();

        $after = $this->rosterRow($learner, $headers);
        $this->assertSame(1, $after['qualifications_completed']);
    }

    /**
     * The important negative. A grant says the learner HOLDS the
     * qualification; it says nothing about what they studied. Inflating the
     * course counts would make the compliance screens lie about coursework.
     */
    public function test_a_grant_does_not_fabricate_course_completions(): void
    {
        $learner = $this->learner();
        $this->courseGranting($learner, completed: false);
        $this->courseGranting($learner, completed: false);

        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();

        $row  = $this->rosterRow($learner, $headers);
        $line = collect($row['qualification_breakdown'])->firstWhere('id', $this->skill->id);

        $this->assertSame(2, $line['courses_total']);
        $this->assertSame(0, $line['courses_completed'], 'A grant must not invent coursework.');
        $this->assertTrue($line['granted_directly']);
        $this->assertTrue($line['earned']);
    }

    public function test_a_qualification_earned_by_study_needs_no_grant(): void
    {
        $learner = $this->learner();
        $this->courseGranting($learner, completed: true);

        ['headers' => $headers] = $this->adminToken();
        $row  = $this->rosterRow($learner, $headers);
        $line = collect($row['qualification_breakdown'])->firstWhere('id', $this->skill->id);

        $this->assertTrue($line['earned']);
        $this->assertFalse($line['granted_directly']);
        $this->assertSame(1, $row['qualifications_completed']);
    }

    // ----------------------------------------------------------------- revoke

    public function test_a_grant_can_be_revoked(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();

        $this->deleteJson($this->url($learner).'/'.$this->skill->id, [], $headers)->assertOk();

        $this->assertSame(0, DB::table('user_qualification_skill')->where('user_id', $learner->id)->count());
    }

    /**
     * Revoking a manual override cannot retract study that actually happened.
     */
    public function test_revoking_a_grant_leaves_a_qualification_earned_by_study_intact(): void
    {
        $learner = $this->learner();
        $this->courseGranting($learner, completed: true);

        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();
        $this->deleteJson($this->url($learner).'/'.$this->skill->id, [], $headers)->assertOk();

        $row  = $this->rosterRow($learner, $headers);
        $line = collect($row['qualification_breakdown'])->firstWhere('id', $this->skill->id);

        $this->assertTrue($line['earned'], 'The courses were still completed.');
        $this->assertFalse($line['granted_directly']);
    }

    public function test_revoking_something_not_granted_is_a_404(): void
    {
        $learner = $this->learner();
        ['headers' => $headers] = $this->adminToken();

        $this->deleteJson($this->url($learner).'/'.$this->skill->id, [], $headers)->assertStatus(404);
    }

    // ------------------------------------------------------------------- bulk

    public function test_one_qualification_can_be_granted_to_many_learners(): void
    {
        $a = $this->learner();
        $b = $this->learner();
        $c = $this->learner();

        ['headers' => $headers] = $this->adminToken();

        $result = $this->postJson(
            self::BASE.'/admin/qualification-skills/'.$this->skill->id.'/learners',
            ['user_ids' => [$a->id, $b->id, $c->id]],
            $headers,
        )->assertCreated()->json('result');

        $this->assertSame(3, $result['granted']);
        $this->assertSame(3, DB::table('user_qualification_skill')
            ->where('qualification_skill_id', $this->skill->id)->count());
    }

    public function test_a_bulk_grant_skips_learners_who_already_hold_it(): void
    {
        $a = $this->learner();
        $b = $this->learner();

        ['headers' => $headers] = $this->adminToken();

        $this->postJson($this->url($a), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertCreated();

        $result = $this->postJson(
            self::BASE.'/admin/qualification-skills/'.$this->skill->id.'/learners',
            ['user_ids' => [$a->id, $b->id]],
            $headers,
        )->assertCreated()->json('result');

        $this->assertSame(1, $result['granted']);
        $this->assertSame(1, $result['already_held']);
        $this->assertSame(2, DB::table('user_qualification_skill')->count());
    }

    /**
     * An unbounded bulk grant is one mis-click from putting a qualification on
     * every employee, and every row then has to be found and revoked by hand.
     */
    public function test_a_bulk_grant_is_bounded(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(
            self::BASE.'/admin/qualification-skills/'.$this->skill->id.'/learners',
            ['user_ids' => range(1, 501)],
            $headers,
        )->assertStatus(422);
    }

    // ------------------------------------------------------------------- read

    public function test_the_grant_list_shows_who_granted_it(): void
    {
        $learner = $this->learner();
        ['headers' => $headers, 'model' => $admin] = $this->adminToken();

        $this->postJson($this->url($learner), [
            'qualification_skill_ids' => [$this->skill->id],
            'note'                    => 'External certificate',
        ], $headers)->assertCreated();

        $granted = $this->getJson($this->url($learner), $headers)->assertOk()->json('result.granted');

        $this->assertCount(1, $granted);
        $this->assertSame('Welding', $granted[0]['name']);
        $this->assertSame($admin->name, $granted[0]['assigned_by_name']);
        $this->assertSame('External certificate', $granted[0]['note']);
    }

    // ------------------------------------------------------------------ authz

    public function test_an_admin_without_the_permission_is_refused(): void
    {
        $learner = $this->learner();

        $role = Role::findOrCreate('no-quals-'.uniqid(), 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson($this->url($learner), $headers)->assertStatus(403);
        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertStatus(403);
    }

    public function test_a_learner_cannot_grant_themselves_a_qualification(): void
    {
        ['headers' => $headers, 'model' => $user] = $this->userToken();

        $this->postJson($this->url($user), ['qualification_skill_ids' => [$this->skill->id]], $headers)
            ->assertStatus(403);

        $this->assertSame(0, DB::table('user_qualification_skill')->count());
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $learner = $this->learner();

        $this->getJson($this->url($learner))->assertStatus(401);
        $this->postJson($this->url($learner), ['qualification_skill_ids' => [$this->skill->id]])
            ->assertStatus(401);
    }
}
