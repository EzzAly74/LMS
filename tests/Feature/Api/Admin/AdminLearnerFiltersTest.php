<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * D3 - the Learners-list filters on GET admin/learners (D-075) (Figma 1986:74701, D-053):
 * course, the course's instructor, qualification, learner type and a
 * last-activity date range. Within one filter any value matches; filters
 * combine with AND; any of them restricts the list to learners.
 */
class AdminLearnerFiltersTest extends ApiTestCase
{
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        ['headers' => $this->headers] = $this->adminToken();
    }

    private function learner(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['learner_type' => 'online']);
    }

    private function enrol(User $user, Course $course): void
    {
        DB::table('users_courses')->insert([
            'user_id' => $user->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function instructor(): Instructor
    {
        return Instructor::query()->create([
            'name' => ['en' => 'Filter Instructor'], 'email' => 'inst'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
    }

    /** @return list<int> user ids returned for the given query */
    private function ids(array $query): array
    {
        return collect($this->getJson(self::BASE.'/admin/learners?'.http_build_query($query + ['per_page' => 100]), $this->headers)
            ->assertOk()->json('result'))
            ->where('source', 'user')->pluck('id')->sort()->values()->all();
    }

    public function test_course_filter_returns_learners_enrolled_in_any_selected_course(): void
    {
        [$a, $b, $c] = [$this->learner(), $this->learner(), $this->learner()];
        [$c1, $c2, $c3] = [Course::factory()->create(), Course::factory()->create(), Course::factory()->create()];
        $this->enrol($a, $c1);
        $this->enrol($b, $c2);
        $this->enrol($c, $c3);

        $this->assertSame([$a->id, $b->id], $this->ids(['course_ids' => [$c1->id, $c2->id]]));
    }

    public function test_instructor_filter_returns_learners_taught_by_them_not_the_instructors(): void
    {
        $inst   = $this->instructor();
        $course = Course::factory()->create();
        DB::table('courses_instructors')->insert(['course_id' => $course->id, 'instructor_id' => $inst->id]);
        $taught = $this->learner();
        $other  = $this->learner();
        $this->enrol($taught, $course);
        $this->enrol($other, Course::factory()->create());

        $rows = $this->getJson(self::BASE.'/admin/learners?'.http_build_query(['course_instructor_ids' => [$inst->id]]), $this->headers)
            ->assertOk()->json('result');

        $this->assertSame([['user', $taught->id]], array_map(fn ($r) => [$r['source'], $r['id']], $rows));
    }

    public function test_qualification_filter_matches_job_title_requirement_or_direct_grant(): void
    {
        $skill    = QualificationSkill::factory()->create();
        $jobTitle = JobTitle::query()->create(['name' => 'Tech', 'name_en' => 'Tech']);
        $jobTitle->qualificationSkills()->attach($skill->id);

        $byJob    = $this->learner(['job_title_id' => $jobTitle->id]);
        $byGrant  = $this->learner();
        $neither  = $this->learner();
        DB::table('user_qualification_skill')->insert([
            'user_id' => $byGrant->id, 'qualification_skill_id' => $skill->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([$byJob->id, $byGrant->id], $this->ids(['qualification_ids' => [$skill->id]]));
        $this->assertNotContains($neither->id, $this->ids(['qualification_ids' => [$skill->id]]));
    }

    public function test_learner_type_filter(): void
    {
        $online  = $this->learner(['learner_type' => 'online']);
        $offline = $this->learner(['learner_type' => 'offline']);
        $hybrid  = $this->learner(['learner_type' => 'hybrid']);

        $this->assertSame([$offline->id, $hybrid->id], $this->ids(['learner_types' => ['offline', 'hybrid']]));
        $this->assertNotContains($online->id, $this->ids(['learner_types' => ['offline']]));
    }

    public function test_last_activity_range_is_inclusive_of_both_calendar_days(): void
    {
        $before = $this->learner(['last_active_at' => '2026-03-31 23:59:59']);
        $first  = $this->learner(['last_active_at' => '2026-04-01 00:00:00']);
        $last   = $this->learner(['last_active_at' => '2026-04-30 23:59:59']);
        $after  = $this->learner(['last_active_at' => '2026-05-01 00:00:00']);
        $never  = $this->learner(['last_active_at' => null]);

        $ids = $this->ids(['active_from' => '2026-04-01', 'active_to' => '2026-04-30']);

        $this->assertSame([$first->id, $last->id], $ids);
        foreach ([$before, $after, $never] as $out) {
            $this->assertNotContains($out->id, $ids);
        }
        // An open-ended range works from either side.
        $this->assertSame([$after->id], $this->ids(['active_from' => '2026-05-01']));
        $this->assertSame([$before->id], $this->ids(['active_to' => '2026-03-31']));
    }

    public function test_filters_combine_with_and(): void
    {
        $course = Course::factory()->create();
        $match  = $this->learner(['learner_type' => 'hybrid']);
        $wrongType = $this->learner(['learner_type' => 'online']);
        $this->enrol($match, $course);
        $this->enrol($wrongType, $course);

        $this->assertSame([$match->id], $this->ids(['course_ids' => [$course->id], 'learner_types' => ['hybrid']]));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        foreach ([
            ['learner_types' => ['remote']],
            ['course_ids' => ['abc']],
            ['course_ids' => '5'],                                   // arrays only
            ['active_from' => '01/04/2026'],
            ['active_from' => '2026-04-30', 'active_to' => '2026-04-01'],
            ['qualification_ids' => range(1, 101)],                  // bounded
        ] as $query) {
            $this->getJson(self::BASE.'/admin/learners?'.http_build_query($query), $this->headers)
                ->assertStatus(422);
        }
    }

    public function test_the_learners_list_never_returns_instructors(): void
    {
        // Instructors are Dashboard accounts, listed in Users (D-075); the
        // learners list holds website learners only, whatever the filters.
        $inst = $this->instructor();

        $rows = $this->getJson(self::BASE.'/admin/learners?instructor_ids='.$inst->id, $this->headers)->assertOk()->json('result');

        $this->assertNotContains('instructor', array_column($rows, 'source'));
    }

    public function test_filters_require_an_authorised_admin(): void
    {
        $this->getJson(self::BASE.'/admin/learners?course_ids[]=1')->assertUnauthorized();

        ['headers' => $learnerHeaders] = $this->userToken();
        $this->assertContains(
            $this->getJson(self::BASE.'/admin/learners?course_ids[]=1', $learnerHeaders)->status(),
            [401, 403],
        );
    }
}
