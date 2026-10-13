<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Models\EvaluationCategory;
use App\Models\Instructor;
use App\Models\User;
use App\Services\CertificatePolicy;
use Tests\Concerns\ConfiguresCertificateRule;

/**
 * The mobile app end to end, screen by screen (S-01 .. S-07 and the
 * Figma 2286 flows), on one learner: browse -> course details -> enrol ->
 * My Learning -> mark present at each session -> evaluate -> certificate
 * issued -> Completed / Certificates -> view and download.
 *
 * Every step asserts what that screen shows, so a break anywhere in the
 * chain (eligibility, completion, lists) fails here.
 */
class MobileJourneyTest extends MobileTestCase
{
    use ConfiguresCertificateRule;

    private const PASSCODE = '24680';

    public function test_a_learner_goes_from_the_catalogue_to_a_downloaded_certificate(): void
    {
        // Platform Config: attendance, 50%. The course is evaluated, so the
        // evaluation is its completion event (D-091).
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 50, minScore: 50);
        $instructor = Instructor::query()->create(['name' => ['en' => 'Youssef Amir', 'ar' => 'يوسف أمير'], 'email' => 'y'.uniqid().'@example.test']);
        $course = Course::factory()->create([
            'title' => ['en' => 'Advanced Excel for Finance', 'ar' => 'إكسل المتقدم للمالية'],
            'course_type' => 'hybrid', 'is_evaluate' => true,
        ]);
        $course->instructors()->sync([$instructor->id]);
        CourseLecture::factory()->create(['course_id' => $course->id, 'content_type' => 'video', 'duration_minutes' => 15]);
        $cohort = CourseSection::factory()->create([
            'course_id' => $course->id, 'status' => 'scheduled', 'capacity' => 24,
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(),
            'enrolment_closes_at' => now()->addDays(2)->toDateString(),
        ]);
        $sessions = collect([3, 4])->map(fn ($d) => CourseSession::factory()->create([
            'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => 'Session 1', 'location' => 'Hybrid · HQ Auditorium',
            'session_date' => now()->addDays($d)->toDateString(), 'time_from' => '09:00:00', 'time_to' => '11:00:00',
        ]));
        $template = EvaluationCategory::query()->create(['name' => ['en' => 'Course Feedback', 'ar' => 'تقييم الدورة'], 'course_id' => $course->id]);
        $question = $template->evaluations()->create(['type' => 'five', 'title' => ['en' => 'Overall', 'ar' => 'عام'], 'is_required' => true]);

        $user = $this->employee();
        $h = $this->headersFor($user);
        $get = fn (string $path) => tap($this->withHeaders($h)->getJson(self::BASE.'/mobile/'.$path), fn ($r) => $r->assertOk());

        // S-01 / S-02: who am I, and the catalogue lists the course.
        $get('me')->assertJsonPath('result.id', $user->id);
        $this->assertContains($course->id, array_column($get('academy/courses')->json('result'), 'id'));

        // S-03 Course Details (Figma 2286:167907) and Schedule (2286:168110).
        $detail = $get('academy/courses/'.$course->id)->json('result');
        $this->assertSame('enrol_now', $detail['cta']['state']);
        $this->assertSame(['lecture'], array_column($detail['content'], 'kind'));
        $this->assertSame([1, 2, 24, 24], [$detail['anchor_cohort']['round'], $detail['anchor_cohort']['sessions_count'],
            $detail['anchor_cohort']['capacity'], $detail['anchor_cohort']['seats_left']]);
        $this->assertSame(['upcoming', 'upcoming'], array_column($detail['anchor_cohort']['sessions'], 'status'));

        // S-04 Enrol.
        $this->withHeaders($h)->postJson(self::BASE.'/mobile/academy/courses/'.$course->id.'/enrol')->assertCreated();
        $this->assertSame('enrolled_view_learning', $get('academy/courses/'.$course->id)->json('result.cta.state'));
        $this->assertNotContains($course->id, array_column($get('academy/courses')->json('result'), 'id'));

        // S-05 My Learning: upcoming, never "at risk" before it starts.
        $card = collect($get('my-learning/active')->json('result'))->firstWhere('id', $course->id);
        $this->assertTrue($card['cohort']['is_upcoming']);
        $this->assertNotSame('at_risk', $card['certificate_status']);
        $this->assertSame(1, $get('my-learning/overview')->json('result.counts.active_courses'));

        // S-06 Mark present at each session, on its day, with its passcode.
        $today = now()->startOfDay();
        foreach ($sessions as $i => $session) {
            $this->travelTo($today->copy()->addDays(3 + $i)->setTime(9, 30));
            $session->update(['passcode' => self::PASSCODE, 'passcode_issued_at' => now(), 'passcode_expires_at' => now()->addMinutes(30), 'attendance_window_minutes' => 30]);
            $mark = $this->withHeaders($h)->postJson(self::BASE.'/mobile/attendance/mark', [
                'course_id' => $course->id, 'session_id' => $session->id, 'passcode' => self::PASSCODE,
            ]);
            $this->assertSame(201, $mark->status(), (string) $mark->getContent());
            // A second tap is refused, not counted twice.
            $this->withHeaders($h)->postJson(self::BASE.'/mobile/attendance/mark', [
                'course_id' => $course->id, 'session_id' => $session->id, 'passcode' => self::PASSCODE,
            ])->assertStatus(409);

            if ($i === 0) {
                // Mid-course: started, one session attended, on track.
                $card = collect($get('my-learning/active')->json('result'))->firstWhere('id', $course->id);
                $this->assertFalse($card['cohort']['is_upcoming']);
                $this->assertSame([1, 0], [$card['progress']['attended_sessions'], $card['progress']['absences']]);
            }
        }
        $this->travelTo(now()->setTime(12, 0));
        $sessionsLog = $get('my-learning/courses/'.$course->id.'/sessions')->json('result');
        $this->assertSame(['present', 'present'], array_column($sessionsLog, 'status'));

        // Evaluation (Figma 2286:168232): the form, then the answer.
        $form = $get('my-learning/courses/'.$course->id.'/evaluate')->json('result');
        $this->assertFalse($form['already_evaluated']);
        $this->assertSame([$question->id], array_column($form['evaluation_categories'][0]['questions'], 'id'));
        $this->withHeaders($h)->postJson(self::BASE.'/mobile/my-learning/courses/'.$course->id.'/evaluate', [
            'instructor_id' => $instructor->id, 'questions' => [$question->id => 5],
        ])->assertCreated();

        // S-07 The certificate is issued and listed; the course is completed.
        $certificates = $get('my-learning/certificates')->json('result');
        $certificate = collect($certificates)->firstWhere('course_id', $course->id) ?? $certificates[0] ?? null;
        $this->assertNotNull($certificate, 'certificate listed');
        $this->assertNotContains($course->id, array_column($get('my-learning/active')->json('result'), 'id'));
        $completed = collect($get('my-learning/completed')->json('result'))->firstWhere('course_id', $course->id);
        $this->assertNotNull($completed, 'listed under Completed');
        // The app links the completed course to its certificate (NEW2B-5793).
        $this->assertSame($certificate['id'], $completed['certificate_id']);
        $get('certificates/'.$certificate['id'])->assertJsonPath('result.id', $certificate['id']);
        // The app's download: the rendered image as base64 JSON.
        $download = $get('certificates/'.$certificate['id'].'/download');
        $download->assertJsonPath('result.mime_type', 'image/jpeg');
        $this->assertNotFalse(base64_decode((string) $download->json('result.image_base64'), true));

        // Another learner cannot open it (IDOR).
        $this->withHeaders($this->headersFor($this->employee()))
            ->getJson(self::BASE.'/mobile/certificates/'.$certificate['id'])->assertNotFound();
    }
}
