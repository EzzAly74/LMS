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
        $c = Course::factory()->create($course + ['course_type' => 'hybrid', 'certificate' => true, 'is_evaluate' => false]);
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

    /** The learner attended both of the cohort's two sessions (100%). */
    private function attendedAll($user, Course $course): void
    {
        $cohortId = (int) DB::table('users_courses')->where('user_id', $user->id)->where('course_id', $course->id)->value('group_id');
        DB::table('course_sections')->where('id', $cohortId)->update(['number_of_sessions' => 2]);
        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $session = DB::table('course_sessions')->insertGetId(['course_id' => $course->id, 'section_id' => $cohortId, 'title' => 'S', 'session_date' => $date, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('attendances')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'section_id' => $cohortId, 'session_id' => $session, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_completing_every_content_item_issues_the_certificate_when_the_rule_is_met(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75);
        [$user, $h, $course, $lectures] = $this->enrolled();
        $this->attendedAll($user, $course);

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

    /** Platform Config / course rule: no measured evidence, no certificate, and not Completed. */
    public function test_content_alone_does_not_beat_the_certificate_rule(): void
    {
        // Attendance required, none recorded.
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75);
        [$user, $h, $course, $lectures] = $this->enrolled();
        DB::table('course_sections')->where('course_id', $course->id)->update(['number_of_sessions' => 2]);
        $this->completeAll($h, $course, $lectures);
        $this->assertSame(0, DB::table('user_certificates')->where('user_id', $user->id)->count());

        // Score required, but the course has no final exam to measure it.
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minScore: 60);
        [$other, $h2, $course2, $lectures2] = $this->enrolled();
        $this->completeAll($h2, $course2, $lectures2);
        $this->assertSame(0, DB::table('user_certificates')->where('user_id', $other->id)->count());
        $this->assertNotContains($course2->id, app(QualificationProgressService::class)->completedCourseIdsForUser($other->id)->all());

        // A course's own rule wins over Platform Config: attendance at 100% clears a course rule of 50%.
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minScore: 60);
        [$third, $h3, $course3, $lectures3] = $this->enrolled([
            'certificate_custom_rule' => true, 'certificate_mode' => CertificatePolicy::BASIS_ATTENDANCE, 'certificate_attendance_threshold' => 50,
        ]);
        $this->attendedAll($third, $course3);
        $this->completeAll($h3, $course3, $lectures3);
        $this->assertDatabaseHas('user_certificates', ['user_id' => $third->id, 'course_id' => $course3->id, 'status' => 'active']);
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

    public function test_content_alone_never_completes_a_course_without_its_certificate(): void
    {
        // D-094: even with the switch off, content is the completion event,
        // never a completion; nothing is issued until the rule is met.
        [$user, $h, $course, $lectures] = $this->enrolled(['certificate' => false]);

        $this->completeAll($h, $course, $lectures);

        $this->assertNotContains($course->id, app(QualificationProgressService::class)->completedCourseIdsForUser($user->id)->all());
    }
}
