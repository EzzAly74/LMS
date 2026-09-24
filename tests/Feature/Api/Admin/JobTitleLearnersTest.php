<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B1 — GET admin/job-titles/{job_title}/learners.
 *
 * The job-title detail table (Figma 2325:117118) had no endpoint at all.
 *
 * Note the index endpoint's card metrics (employees, learners, qualification
 * count, compliance %) turned out to already exist — 02-figma-map.md guessed
 * they were "likely missing" and that guess was wrong — so only this endpoint
 * was built. See JobTitleCardMetricsTest for the check that they are real.
 */
class JobTitleLearnersTest extends ApiTestCase
{
    private JobTitle $jobTitle;

    private QualificationSkill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobTitle = JobTitle::query()->create(['name' => 'Technician']);
        $this->skill    = QualificationSkill::query()->create(['name' => 'Welding']);
        $this->jobTitle->qualificationSkills()->attach($this->skill->id);
    }

    /** Enrol a learner in $total relevant courses, $completed of them finished. */
    private function learnerWith(int $total, int $completed, string $name = 'Learner'): User
    {
        $user = User::factory()->create([
            'name'         => $name,
            'job_title_id' => $this->jobTitle->id,
        ]);

        for ($i = 0; $i < $total; $i++) {
            $course = Course::factory()->create();
            DB::table('course_qualification_skills')->insert([
                'course_id'              => $course->id,
                'qualification_skill_id' => $this->skill->id,
            ]);

            $created = now()->subDays(10);
            DB::table('users_courses')->insert([
                'user_id'    => $user->id,
                'course_id'  => $course->id,
                'created_at' => $created,
                // "completed" is the project-wide heuristic: updated_at > created_at.
                'updated_at' => $i < $completed ? $created->copy()->addDay() : $created,
            ]);
        }

        return $user;
    }

    private function url(?JobTitle $jt = null): string
    {
        return self::BASE.'/admin/job-titles/'.($jt ?? $this->jobTitle)->id.'/learners';
    }

    // ---------------------------------------------------------------- allowed

    public function test_an_admin_sees_learners_with_completion_metrics(): void
    {
        $this->learnerWith(total: 4, completed: 1, name: 'Ali');
        ['headers' => $headers] = $this->adminToken();

        $response = $this->getJson($this->url(), $headers)->assertOk();

        $row = $response->json('result.0');

        $this->assertSame('Ali', $row['name']);
        $this->assertSame(1, $row['courses']['completed']);
        $this->assertSame(4, $row['courses']['total']);
        $this->assertSame('1 of 4', $row['courses']['label']);
        $this->assertSame(25, $row['completion_percent']);
        $this->assertSame('Welding', $row['qualifications'][0]['name']);
    }

    public function test_a_learner_with_no_relevant_enrolments_is_zero_not_null(): void
    {
        User::factory()->create(['name' => 'Idle', 'job_title_id' => $this->jobTitle->id]);
        ['headers' => $headers] = $this->adminToken();

        $row = $this->getJson($this->url(), $headers)->assertOk()->json('result.0');

        $this->assertSame(0, $row['completion_percent']);
        $this->assertSame('0 of 0', $row['courses']['label']);
    }

    public function test_only_learners_of_this_job_title_are_returned(): void
    {
        $this->learnerWith(2, 2, 'Mine');
        $other = JobTitle::query()->create(['name' => 'Driver']);
        User::factory()->create(['name' => 'Theirs', 'job_title_id' => $other->id]);

        ['headers' => $headers] = $this->adminToken();

        $names = collect($this->getJson($this->url(), $headers)->assertOk()->json('result'))
            ->pluck('name');

        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_search_filters_by_name_and_employee_id(): void
    {
        $this->learnerWith(1, 1, 'Findable');
        $this->learnerWith(1, 0, 'Hidden');
        ['headers' => $headers] = $this->adminToken();

        $names = collect($this->getJson($this->url().'?search=Findable', $headers)->assertOk()->json('result'))
            ->pluck('name');

        $this->assertSame(['Findable'], $names->all());
    }

    public function test_results_can_be_sorted_by_completion(): void
    {
        $this->learnerWith(4, 1, 'Low');
        $this->learnerWith(4, 4, 'High');
        ['headers' => $headers] = $this->adminToken();

        $names = collect($this->getJson($this->url().'?sort=completion&dir=desc', $headers)->assertOk()->json('result'))
            ->pluck('name');

        $this->assertSame('High', $names->first());
    }

    // ------------------------------------------------------------- pagination

    public function test_pagination_is_bounded(): void
    {
        ['headers' => $headers] = $this->adminToken();

        // B-21: 41 existing controllers accept an unbounded per_page. New
        // endpoints reject it instead of letting a caller pull the whole table.
        $this->getJson($this->url().'?per_page=100000', $headers)->assertStatus(422);
        $this->getJson($this->url().'?per_page=0', $headers)->assertStatus(422);
        $this->getJson($this->url().'?per_page=25', $headers)->assertOk();
    }

    public function test_pagination_returns_the_requested_page_size(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->learnerWith(1, 1, "L{$i}");
        }
        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson($this->url().'?per_page=2', $headers)->assertOk()->json('result');

        $this->assertCount(2, $result);
    }

    // ---------------------------------------------------------- invalid input

    public function test_an_unknown_sort_key_is_rejected(): void
    {
        ['headers' => $headers] = $this->adminToken();

        // Guards the ORDER BY: the direction is interpolated, so only
        // allow-listed values may reach the repository.
        $this->getJson($this->url().'?sort=name;DROP TABLE users', $headers)->assertStatus(422);
        $this->getJson($this->url().'?dir=sideways', $headers)->assertStatus(422);
    }

    public function test_an_unknown_job_title_is_404(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/job-titles/999999/learners', $headers)->assertStatus(404);
    }

    // -------------------------------------------------------------- forbidden

    public function test_an_admin_without_the_permission_is_refused(): void
    {
        $role  = \Spatie\Permission\Models\Role::findOrCreate('jt-restricted', 'admin');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = \App\Models\Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson($this->url(), $headers)->assertStatus(403);
    }

    public function test_a_learner_cannot_read_the_roster(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson($this->url(), $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    // ------------------------------------------------------------------- N+1

    public function test_the_listing_does_not_issue_a_query_per_row(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $measure = function (string $url) use ($headers): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson($url, $headers)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Absolute counts are dominated by per-request overhead (token lookup,
        // role and permission loading), which is why this measures the *delta*
        // as the row count grows instead of asserting a fixed ceiling. An N+1
        // shows up as the delta tracking the number of rows; constant work
        // shows up as roughly zero.
        $this->learnerWith(2, 1, 'Only');
        $withOneRow = $measure($this->url().'?per_page=10');

        for ($i = 0; $i < 6; $i++) {
            $this->learnerWith(2, 1, "N{$i}");
        }
        $withSevenRows = $measure($this->url().'?per_page=10');

        $this->assertLessThanOrEqual(
            2,
            $withSevenRows - $withOneRow,
            "Query count grew from {$withOneRow} to {$withSevenRows} when six rows were added — that is an N+1.",
        );
    }
}
