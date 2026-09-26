<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * D-056: one rule for "this learner holds this qualification" - a direct
 * grant, or every linked course completed - on every admin screen that shows
 * it. Before, the job-title detail counted only enrolled courses, the
 * job-title card counted any one passed course and ignored grants, and the
 * learner-detail tile counted any one passed course.
 */
class QualificationHoldingTest extends ApiTestCase
{
    private JobTitle $jobTitle;

    private QualificationSkill $skill;

    private Course $first;

    private Course $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobTitle = JobTitle::query()->create(['name' => 'Technician']);
        $this->skill    = QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'لحام']]);
        $this->jobTitle->qualificationSkills()->attach($this->skill->id);

        $this->first  = Course::factory()->create();
        $this->second = Course::factory()->create();
        foreach ([$this->first, $this->second] as $c) {
            DB::table('course_qualification_skills')->insert(['course_id' => $c->id, 'qualification_skill_id' => $this->skill->id]);
        }
    }

    private function pass(User $u, Course $c): void
    {
        DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
        $exam = CourseExam::factory()->create(['course_id' => $c->id]);
        DB::table('user_exams')->insert([
            'user_id' => $u->id, 'course_id' => $c->id, 'exam_id' => $exam->id, 'status' => 'passed',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grant(User $u): void
    {
        DB::table('user_qualification_skill')->insert([
            'user_id' => $u->id, 'qualification_skill_id' => $this->skill->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function detailRow(User $u, array $headers): array
    {
        return collect($this->getJson(self::BASE.'/admin/job-titles/'.$this->jobTitle->id.'/learners', $headers)->assertOk()->json('result'))
            ->firstWhere('id', $u->id);
    }

    private function card(array $headers): array
    {
        return collect($this->getJson(self::BASE.'/job-titles', $headers)->assertOk()->json('result'))->firstWhere('id', $this->jobTitle->id);
    }

    private function tile(User $u, array $headers): int
    {
        return $this->getJson(self::BASE.'/admin/learners/'.$u->id, $headers)->assertOk()->json('result.tiles.earned_qualifications');
    }

    public function test_passing_one_of_two_linked_courses_is_not_holding_it_on_any_screen(): void
    {
        // Enrolled in (and passed) only the first course: the old job-title
        // detail read this as "1 of 1 Courses - earned".
        $u = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $this->pass($u, $this->first);
        ['headers' => $headers] = $this->adminToken();

        $line = $this->detailRow($u, $headers)['qualification_breakdown'][0];
        $this->assertSame(2, $line['courses_total']);
        $this->assertSame(1, $line['courses_completed']);
        $this->assertSame(50, $line['percent']);
        $this->assertFalse($line['earned']);
        $this->assertSame('1 of 2', $this->detailRow($u, $headers)['courses']['label']);

        $this->assertSame(0, $this->card($headers)['compliance_percent']);
        $this->assertSame(0, $this->tile($u, $headers));
    }

    public function test_passing_every_linked_course_is_holding_it_on_every_screen(): void
    {
        $u = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $this->pass($u, $this->first);
        $this->pass($u, $this->second);
        ['headers' => $headers] = $this->adminToken();

        $this->assertTrue($this->detailRow($u, $headers)['qualification_breakdown'][0]['earned']);
        $this->assertSame(100, $this->card($headers)['compliance_percent']);
        $this->assertSame(1, $this->tile($u, $headers));
    }

    public function test_a_direct_grant_is_holding_it_on_every_screen(): void
    {
        $u = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $this->grant($u);
        ['headers' => $headers] = $this->adminToken();

        $line = $this->detailRow($u, $headers)['qualification_breakdown'][0];
        $this->assertTrue($line['earned']);
        $this->assertSame(0, $line['courses_completed'], 'A grant never invents coursework.');
        // The job-title card ignored direct grants entirely.
        $this->assertSame(100, $this->card($headers)['compliance_percent']);
        $this->assertSame(1, $this->tile($u, $headers));
    }

    public function test_compliance_counts_every_employee_not_only_the_enrolled_ones(): void
    {
        $done = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $this->pass($done, $this->first);
        $this->pass($done, $this->second);
        User::factory()->count(3)->create(['job_title_id' => $this->jobTitle->id]); // not started
        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(25, $this->card($headers)['compliance_percent']);
    }
}
