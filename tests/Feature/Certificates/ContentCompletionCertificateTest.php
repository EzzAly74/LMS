<?php

namespace Tests\Feature\Certificates;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\UsersCourse;
use App\Services\CertificatePolicy;
use App\Services\Mobile\QualificationProgressService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresCertificateRule;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-6205: a learner who completes a course gets its certificate. One rule
 * drives the Completed badge and the certificate.
 */
class ContentCompletionCertificateTest extends ApiTestCase
{
    use ConfiguresCertificateRule;

    private function enrolled(array $course = []): array
    {
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $c = Course::factory()->create($course + ['certificate' => true, 'is_evaluate' => false]);
        $cohort = CourseSection::factory()->create(['course_id' => $c->id]);
        UsersCourse::factory()->create(['user_id' => $user->id, 'course_id' => $c->id, 'group_id' => $cohort->id]);
        $lectures = CourseLecture::factory()->count(2)->create(['course_id' => $c->id, 'section_id' => $cohort->id]);

        return [$user, $h, $c, $lectures];
    }

    private function completeAll($h, Course $course, $lectures): void
    {
        foreach ($lectures as $lecture) {
            $this->postJson(self::BASE."/courses/{$course->id}/lectures/{$lecture->id}/progress", ['confirmed' => true], $h)->assertOk();
        }
    }

    public function test_completing_every_content_item_issues_the_certificate(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minScore: 60);
        [$user, $h, $course, $lectures] = $this->enrolled();

        $this->postJson(self::BASE."/courses/{$course->id}/lectures/{$lectures[0]->id}/progress", ['confirmed' => true], $h)->assertOk();
        $this->assertSame(0, DB::table('user_certificates')->where('user_id', $user->id)->count());
        $this->assertNotContains($course->id, app(QualificationProgressService::class)->completedCourseIdsForUser($user->id)->all());

        $this->postJson(self::BASE."/courses/{$course->id}/lectures/{$lectures[1]->id}/progress", ['confirmed' => true], $h)->assertOk();

        $this->assertDatabaseHas('user_certificates', ['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active', 'source_type' => 'content']);
        $this->assertContains($course->id, app(QualificationProgressService::class)->completedCourseIdsForUser($user->id)->all());

        $card = collect($this->getJson(self::BASE.'/my/learnings', $h)->assertOk()->json('result'))->firstWhere('id', $course->id);
        $this->assertTrue($card['completed']);
        $this->assertNotNull($card['certificate_id']);
    }

    public function test_a_course_with_a_final_exam_is_not_completed_by_content_alone(): void
    {
        [$user, $h, $course, $lectures] = $this->enrolled();
        CourseExam::factory()->create(['course_id' => $course->id, 'is_final' => true]);

        $this->completeAll($h, $course, $lectures);

        $this->assertSame(0, DB::table('user_certificates')->where('user_id', $user->id)->count());
        // Not "Completed" either: badge and certificate agree.
        $this->assertNotContains($course->id, app(QualificationProgressService::class)->completedCourseIdsForUser($user->id)->all());
        $card = collect($this->getJson(self::BASE.'/my/learnings', $h)->assertOk()->json('result'))->firstWhere('id', $course->id);
        $this->assertFalse($card['completed']);
    }

    public function test_a_course_without_a_certificate_is_completed_by_its_content(): void
    {
        [$user, $h, $course, $lectures] = $this->enrolled(['certificate' => false]);

        $this->completeAll($h, $course, $lectures);

        $this->assertSame(0, DB::table('user_certificates')->where('user_id', $user->id)->count());
        $this->assertContains($course->id, app(QualificationProgressService::class)->completedCourseIdsForUser($user->id)->all());
    }
}
