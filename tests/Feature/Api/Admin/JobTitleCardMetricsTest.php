<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B1 — the job-title index card metrics.
 *
 * 02-figma-map.md recorded these as "**likely missing** from the payload" for
 * node 2078:102691. That was a guess, and verification showed it was wrong:
 * JobTitleRepository::list already projects employees_count, learners_count,
 * completed_qualifications_count and qualificationSkills_count, and
 * JobTitleResource already renders all four plus compliance_percent.
 *
 * Nothing was built for it. This test exists so the claim is backed by
 * evidence rather than by reading, and so the metrics cannot silently regress.
 */
class JobTitleCardMetricsTest extends ApiTestCase
{
    public function test_the_index_already_returns_the_figma_card_metrics(): void
    {
        $jobTitle = JobTitle::query()->create(['name' => 'Technician']);
        $skill    = QualificationSkill::query()->create(['name' => 'Welding']);
        $jobTitle->qualificationSkills()->attach($skill->id);

        $course = Course::factory()->create();
        DB::table('course_qualification_skills')->insert([
            'course_id'              => $course->id,
            'qualification_skill_id' => $skill->id,
        ]);

        // Two employees; one of them enrolled and finished the course.
        $enrolled = User::factory()->create(['job_title_id' => $jobTitle->id]);
        User::factory()->create(['job_title_id' => $jobTitle->id]);

        $created = now()->subDays(5);
        DB::table('users_courses')->insert([
            'user_id'    => $enrolled->id,
            'course_id'  => $course->id,
            'created_at' => $created,
            'updated_at' => $created->copy()->addDay(),
        ]);

        // Completion is a passing exam, not a moved updated_at (B-104).
        $exam = \App\Models\CourseExam::factory()->create(['course_id' => $course->id]);
        DB::table('user_exams')->insert([
            'user_id'      => $enrolled->id,
            'course_id'    => $course->id,
            'exam_id'      => $exam->id,
            'status'       => 'passed',
            'submitted_at' => now()->subDays(2),
            'created_at'   => now()->subDays(2),
            'updated_at'   => now()->subDays(2),
        ]);

        ['headers' => $headers] = $this->adminToken();

        $row = collect($this->getJson(self::BASE.'/job-titles', $headers)->assertOk()->json('result'))
            ->firstWhere('id', $jobTitle->id);

        $this->assertNotNull($row, 'The job title should appear in the index.');

        $this->assertSame(2, $row['employees_count']);
        $this->assertSame(1, $row['learners_count']);
        $this->assertSame(1, $row['qualifications_count']);

        // 1 completed (learner, qualification) pair / (1 learner x 1 qual) = 100%.
        $this->assertSame(100, $row['compliance_percent']);
    }

    public function test_compliance_is_zero_when_there_is_nothing_to_comply_with(): void
    {
        $jobTitle = JobTitle::query()->create(['name' => 'Unassigned']);
        User::factory()->create(['job_title_id' => $jobTitle->id]);

        ['headers' => $headers] = $this->adminToken();

        $row = collect($this->getJson(self::BASE.'/job-titles', $headers)->assertOk()->json('result'))
            ->firstWhere('id', $jobTitle->id);

        $this->assertSame(0, $row['compliance_percent']);
        $this->assertSame(0, $row['learners_count']);
    }
}
