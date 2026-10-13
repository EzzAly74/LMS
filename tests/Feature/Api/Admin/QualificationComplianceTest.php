<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-173 / D-093: a learner's qualification compliance is the mean of their
 * job title's qualification percents, a direct grant counting 100. The
 * learners list and the job-title page show the same number.
 */
class QualificationComplianceTest extends ApiTestCase
{
    public function test_grants_count_and_both_screens_agree(): void
    {
        $job = JobTitle::query()->create(['name' => 'Analyst '.uniqid(), 'name_en' => 'Analyst']);
        $granted = QualificationSkill::factory()->create();
        $halfDone = QualificationSkill::factory()->create();
        $job->qualificationSkills()->attach([$granted->id, $halfDone->id]);

        // Two courses for the second qualification; one completed (certificate held).
        [$c1, $c2] = [Course::factory()->create(['certificate' => true]), Course::factory()->create(['certificate' => true])];
        foreach ([$c1, $c2] as $c) {
            DB::table('course_qualification_skills')->insert(['course_id' => $c->id, 'qualification_skill_id' => $halfDone->id]);
        }

        $learner = User::factory()->create(['job_title_id' => $job->id, 'name_en' => 'Compliance Case']);
        DB::table('users_courses')->insert(['user_id' => $learner->id, 'course_id' => $c1->id, 'created_at' => now(), 'updated_at' => now()]);
        UserCertificate::factory()->create(['user_id' => $learner->id, 'course_id' => $c1->id]);
        DB::table('user_qualification_skill')->insert(['user_id' => $learner->id, 'qualification_skill_id' => $granted->id, 'created_at' => now(), 'updated_at' => now()]);

        ['headers' => $h] = $this->adminToken();

        // (100 granted + 50 half done) / 2 = 75.
        $row = collect($this->getJson(self::BASE.'/admin/learners?search=Compliance', $h)->assertOk()->json('result'))->firstWhere('id', $learner->id);
        $this->assertSame(75, $row['compliance_pct']);

        $jobRow = collect($this->getJson(self::BASE."/admin/job-titles/{$job->id}/learners", $h)->assertOk()->json('result'))->firstWhere('id', $learner->id);
        $this->assertSame(75, $jobRow['completion_percent']);
    }
}
