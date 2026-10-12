<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\UserCertificate;

/**
 * Mobile parity with the Website (B-163, `_audit/11-completion-mobile-audit.md`).
 *
 * NEW2B-5793: a certified course left the mobile active list and the app had
 * no list carrying its certificate id, so it showed "soon" while the Website
 * could download it. The completed list, the D-083 counters, the evaluation
 * and the account-status check now match the Website.
 */
class MobileParityTest extends MobileTestCase
{
    public function test_a_completed_course_is_listed_with_its_certificate_and_downloads(): void
    {
        $user   = $this->employee();
        $course = Course::factory()->create(['certificate' => true]);
        $course->users()->attach($user->id);
        $certificate = UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

        $completed = $this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/completed');
        $this->assertSuccess($completed);
        $row = collect($completed->json('result'))->firstWhere('course_id', $course->id)
            ?? collect($completed->json('result'))->firstWhere('id', $course->id);
        $this->assertNotNull($row, 'The completed course is listed on mobile.');
        $this->assertSame($certificate->id, $row['certificate_id']);

        $download = $this->withHeaders($this->headersFor($user))
            ->getJson(self::BASE.'/mobile/certificates/'.$certificate->id.'/download');
        $this->assertSuccess($download);
        $this->assertSame('image/jpeg', $download->json('result.mime_type'));
    }

    public function test_the_mobile_counters_are_the_website_counters(): void
    {
        $user = $this->employee();
        $done = Course::factory()->create();
        $open = Course::factory()->create();
        $done->users()->attach($user->id);
        $open->users()->attach($user->id);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $done->id]);

        $response = $this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/summary');

        $this->assertSuccess($response);
        $this->assertSame(1, $response->json('result.counts.earned'));
        $this->assertSame(1, $response->json('result.counts.in_progress'));
    }

    public function test_the_evaluation_is_reachable_for_an_enrolled_learner_only(): void
    {
        $user   = $this->employee();
        $course = Course::factory()->create(['is_evaluate' => true]);

        $this->assertError(
            $this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/courses/'.$course->id.'/evaluate'),
            403,
        );

        $course->users()->attach($user->id);
        $this->assertSuccess(
            $this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/courses/'.$course->id.'/evaluate'),
        );
    }

    public function test_an_inactive_employee_is_refused(): void
    {
        $user = $this->employee(['status' => 'deactivated']);

        $this->assertError($this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/me'), 403);
    }

    public function test_each_employee_has_their_own_rate_limit_bucket(): void
    {
        // Every employee comes through the HR server's IP: one exhausted
        // employee must not block the next one.
        $first  = $this->employee();
        $second = $this->employee();

        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders($this->headersFor($first))->getJson(self::BASE.'/mobile/me');
        }
        $this->withHeaders($this->headersFor($first))->getJson(self::BASE.'/mobile/me')->assertStatus(429);
        $this->assertSuccess($this->withHeaders($this->headersFor($second))->getJson(self::BASE.'/mobile/me'));
    }
}
