<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Models\User;
use App\Models\UsersCourse;
use App\Services\CertificatePolicy;
use App\Services\CertificateProjectionService;
use App\Services\LectureProgressService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresCertificateRule;

/**
 * Mobile active-course cards agree with the Website (audit 2026-10-13):
 * the certificate projection uses the course's own rule (the card query
 * used to drop those columns, so every course fell back to Platform
 * Config), progress is the Website's figure, attendance counts distinct
 * sessions, and a page of cards costs a fixed number of queries.
 */
class ActiveCardParityTest extends MobileTestCase
{
    use ConfiguresCertificateRule;

    /** @return array{0: Course, 1: CourseSection, 2: list<int>} */
    private function enrolled(User $user, array $course = []): array
    {
        $course = Course::factory()->create($course);
        $cohort = CourseSection::factory()->running()->create(['course_id' => $course->id]);
        UsersCourse::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort->id]);
        $sessions = [];
        foreach ([-6, -4, -2, 5] as $day) {
            $sessions[] = CourseSession::factory()->create([
                'course_id' => $course->id, 'section_id' => $cohort->id,
                'session_date' => now()->addDays($day)->toDateString(), 'time_from' => '09:00:00', 'time_to' => '10:00:00',
            ])->id;
        }

        return [$course, $cohort, $sessions];
    }

    private function card(User $user, int $courseId): array
    {
        $response = $this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/active');
        $this->assertSuccess($response);

        return collect($response->json('result'))->firstWhere('id', $courseId);
    }

    public function test_the_certificate_projection_uses_the_courses_own_rule(): void
    {
        // Platform Config wants attendance; this course only a score.
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 80, minScore: 50);
        $user = $this->employee();
        [$course] = $this->enrolled($user, [
            'certificate_custom_rule' => true, 'certificate_mode' => 'score', 'certificate_score_threshold' => 60,
        ]);

        $card = $this->card($user, $course->id);
        $web = app(CertificateProjectionService::class)->projectForCourse($user, $course->fresh());

        $this->assertSame($web, $card['certificate_projection']);
        $this->assertSame($web['status'], $card['certificate_status']);
        // Not at risk for missing attendance: the course does not ask for it.
        $this->assertNotSame('at_risk', $card['certificate_status']);
    }

    public function test_progress_and_attendance_match_the_website(): void
    {
        $user = $this->employee();
        [$course, $cohort, $sessions] = $this->enrolled($user);
        // Attended the first session twice (a duplicate row) and the second once.
        foreach ([$sessions[0], $sessions[0], $sessions[1]] as $sessionId) {
            DB::table('attendances')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'section_id' => $cohort->id,
                'session_id' => $sessionId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $card = $this->card($user, $course->id);

        $this->assertSame(2, $card['progress']['attended_sessions']);
        $this->assertSame(1, $card['progress']['absences']);
        $this->assertSame(app(LectureProgressService::class)->getCourseProgress($user->id, $course->id), $card['progress']['percent']);
        $this->assertSame(50, $card['progress']['percent']);
    }

    public function test_a_page_of_cards_costs_a_bounded_number_of_queries(): void
    {
        $user = $this->employee();
        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSuccess($this->withHeaders($this->headersFor($user))->getJson(self::BASE.'/mobile/my-learning/active'));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        [$c1] = $this->enrolled($user);
        CourseLecture::factory()->count(2)->create(['course_id' => $c1->id]);
        $one = $count();
        foreach (range(1, 4) as $i) {
            $this->enrolled($user);
        }
        $five = $count();

        // The per-card queries left (progress summary, next / live session)
        // are a handful; the projection, sessions and ratings are batched.
        $this->assertLessThanOrEqual(12, ($five - $one) / 4, "per extra card: {$one} -> {$five}");
    }
}
