<?php

namespace Tests\Feature\Api\Learner;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Profile header counters (D-083, NEW2B-5770 / 5776 / 5778): Required and
 * Not started follow the job title's required courses; Earned and In progress
 * count every course, including ones the learner joined on their own.
 */
class ProfileCountersTest extends ApiTestCase
{
    private function enrol(User $user, Course $course): void
    {
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function counts(array $headers): array
    {
        return $this->getJson(self::BASE.'/learner/profile/summary', $headers)->assertOk()->json('result.counts');
    }

    public function test_self_chosen_courses_count_in_earned_and_in_progress(): void
    {
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $title = JobTitle::factory()->create();
        $user->forceFill(['job_title_id' => $title->id])->save();
        $qualification = QualificationSkill::factory()->create();
        DB::table('job_title_qualification_skill')->insert(['job_title_id' => $title->id, 'qualification_skill_id' => $qualification->id]);

        [$reqDone, $reqActive, $reqUntouched, $reqUntouched2] = Course::factory()->count(4)->create()->all();
        foreach ([$reqDone, $reqActive, $reqUntouched, $reqUntouched2] as $c) {
            DB::table('course_qualification_skills')->insert(['course_id' => $c->id, 'qualification_skill_id' => $qualification->id]);
        }
        [$ownActive, $ownDone] = Course::factory()->count(2)->create()->all();

        $this->enrol($user, $reqDone);
        $this->enrol($user, $reqActive);
        $this->enrol($user, $ownActive);
        $this->enrol($user, $ownDone);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $reqDone->id]);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $ownDone->id]);

        $this->assertSame(['required' => 4, 'earned' => 2, 'in_progress' => 2, 'not_started' => 2], $this->counts($h));
    }

    public function test_a_learner_without_a_job_title_still_sees_their_own_courses(): void
    {
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $user->forceFill(['job_title_id' => null])->save();
        $this->enrol($user, Course::factory()->create());

        $this->assertSame(['required' => 0, 'earned' => 0, 'in_progress' => 1, 'not_started' => 0], $this->counts($h));
    }

    public function test_a_revoked_certificate_is_not_earned(): void
    {
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $course = Course::factory()->create();
        $this->enrol($user, $course);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => UserCertificate::STATUS_REVOKED]);

        $this->assertSame(0, $this->counts($h)['earned']);
        $this->assertSame(1, $this->counts($h)['in_progress']);
    }

    public function test_the_counters_need_a_signed_in_learner(): void
    {
        $this->getJson(self::BASE.'/learner/profile/summary')->assertUnauthorized();
    }
}
