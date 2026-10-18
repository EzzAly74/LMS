<?php

namespace Tests\Feature\Api\Admin\Reports;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseExam;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\CertificateService;
use Illuminate\Support\Facades\DB;

/**
 * 3. Attendance, 4. Completion, 5. Quiz Scores, 6. Assignment Scores and
 * 7. Certificate Status (D-112).
 */
class LearningReportsTest extends ReportTestCase
{
    private function enrol(User $user, Course $course, ?CourseSection $cohort = null, ?string $at = null): void
    {
        DB::table('users_courses')->insert([
            'user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort?->id,
            'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);
    }

    /** A cohort with sessions of two hours: `$held` in the past, `$upcoming` ahead. @return list<CourseSession> */
    private function cohortWithSessions(Course $course, int $held, int $upcoming, array $cohort = []): array
    {
        $section = CourseSection::factory()->create($cohort + ['course_id' => $course->id, 'start_date' => now()->subDays(30)->toDateString(), 'end_date' => now()->addDays(30)->toDateString(), 'status' => 'active']);
        $sessions = [];
        for ($i = 0; $i < $held + $upcoming; $i++) {
            $sessions[] = CourseSession::factory()->create([
                'course_id' => $course->id, 'section_id' => $section->id,
                'session_date' => $i < $held ? now()->subDays(10 - $i)->toDateString() : now()->addDays(5 + $i)->toDateString(),
                'time_from' => '10:00:00', 'time_to' => '12:00:00',
            ]);
        }

        return $sessions;
    }

    private function attend(User $user, CourseSession $session, float $hours = 2): void
    {
        Attendance::factory()->create([
            'user_id' => $user->id, 'course_id' => $session->course_id, 'section_id' => $session->section_id,
            'session_id' => $session->id, 'attendance_hours' => $hours,
        ]);
    }

    public function test_attendance_counts_sessions_and_hours_and_judges_by_the_certificate_threshold(): void
    {
        $course = Course::factory()->create(['course_type' => 'offline', 'number_of_sessions' => 4]);
        DB::table('courses_instructors')->insert(['course_id' => $course->id, 'instructor_id' => $this->instructor()->id]);
        $sessions = $this->cohortWithSessions($course, 2, 2);
        $cohort = CourseSection::query()->find($sessions[0]->section_id);
        [$partial, $noShow] = [User::factory()->create(['name_ar' => 'جزئي']), User::factory()->create(['name_ar' => 'غائب'])];
        $this->enrol($partial, $course, $cohort);
        $this->enrol($noShow, $course, $cohort);
        $this->attend($partial, $sessions[0]);
        $this->attend($partial, $sessions[1]);

        $done = $this->cohortWithSessions($course2 = Course::factory()->create(), 3, 0);
        $doneCohort = CourseSection::query()->find($done[0]->section_id);
        [$completed, $atRisk] = [User::factory()->create(['name_ar' => 'مكتمل']), User::factory()->create(['name_ar' => 'متعثر'])];
        $this->enrol($completed, $course2, $doneCohort);
        $this->enrol($atRisk, $course2, $doneCohort);
        foreach ($done as $s) {
            $this->attend($completed, $s);
        }
        $this->attend($atRisk, $done[0]);
        // An online enrolment with no sessions and no attendance is not listed.
        $this->enrol(User::factory()->create(), Course::factory()->create());

        $rows = collect($this->rows('attendance'))->keyBy('full_name_ar');

        $this->assertCount(4, $rows);
        $p = $rows['جزئي'];
        $this->assertSame([4, 2], [$p['required_sessions'], $p['sessions_attended']]);
        $this->assertEquals([2, 4, 50, 8, 50, 4], [$p['hours_per_session'], $p['attendance_hours'], $p['attendance_pct_sessions'], $p['total_course_hours'], $p['attendance_pct_hours'], $p['absence_hours']]);
        $this->assertSame(['Partial', 'Offline', 'Ahmed Zidan'], [$p['attendance_status'], $p['delivery_mode'], $p['instructor']]);
        $this->assertSame($cohort->start_date->toDateString(), $p['group_start_date']);
        $this->assertSame('No Show', $rows['غائب']['attendance_status']);
        $this->assertSame('Completed', $rows['مكتمل']['attendance_status']);
        $this->assertSame('At Risk', $rows['متعثر']['attendance_status']);

        $below = array_column($this->rows('attendance', ['attendance_below' => 60]), 'full_name_ar');
        sort($below);
        $this->assertSame(['جزئي', 'غائب', 'متعثر'], $below);
        $this->assertSame(['جزئي', 'غائب'], collect($this->rows('attendance', ['cohort_ids' => [$cohort->id]]))->pluck('full_name_ar')->sort()->values()->all());
    }

    public function test_attendance_status_is_blank_before_any_session_and_hours_and_sessions_disagreement_is_visible(): void
    {
        $course = Course::factory()->create(['course_type' => 'hybrid']);
        $upcoming = $this->cohortWithSessions($course, 0, 3);
        $learner = User::factory()->create(['name_ar' => 'قادم']);
        $this->enrol($learner, $course, CourseSection::query()->find($upcoming[0]->section_id));

        $row = $this->rows('attendance')[0];

        $this->assertSame([3, 0], [$row['required_sessions'], $row['sessions_attended']]);
        $this->assertNull($row['attendance_status']);
        $this->assertSame('Hybrid', $row['delivery_mode']);
    }

    public function test_completion_reports_status_dates_progress_and_final_score(): void
    {
        $course = Course::factory()->create();
        $ended = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(5)->toDateString(), 'status' => 'active']);
        $running = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'status' => 'active']);
        $final = CourseExam::factory()->create(['course_id' => $course->id, 'section_id' => null, 'is_final' => true, 'degree' => 20, 'pass_score' => 10]);
        [$done, $failed, $going, $idle] = array_map(fn ($n) => User::factory()->create(['name_en' => $n]), ['Done', 'Failed', 'Going', 'Idle']);
        $this->enrol($done, $course, $ended, '2026-05-01 09:00:00');
        $this->enrol($failed, $course, $ended);
        $this->enrol($going, $course, $running);
        $this->enrol($idle, $course, $running);
        UserCertificate::factory()->create(['user_id' => $done->id, 'course_id' => $course->id, 'issued_at' => '2026-05-20 10:00:00']);
        DB::table('user_exams')->insert([
            'user_id' => $done->id, 'course_id' => $course->id, 'exam_id' => $final->id, 'user_degree' => 0,
            'total_score' => 17, 'max_score' => 20, 'submission_status' => 'submitted', 'submitted_at' => '2026-05-03 08:30:00',
            'status' => 'success', 'created_at' => '2026-05-03 08:00:00', 'updated_at' => now(),
        ]);
        Attendance::factory()->create(['user_id' => $going->id, 'course_id' => $course->id, 'created_at' => now()->subDay()]);

        $rows = collect($this->rows('completion'))->keyBy('full_name_en');

        $d = $rows['Done'];
        $this->assertSame(['Completed', '2026-05-01', '2026-05-03', '2026-05-20', 'Pass', true],
            [$d['completion_status'], $d['enrollment_date'], $d['start_date'], $d['completion_date'], $d['result'], $d['certificate_issued']]);
        $this->assertEquals([100, 85], [$d['progress_pct'], $d['final_score']]);
        $this->assertSame(['Failed', false, null], [$rows['Failed']['completion_status'], $rows['Failed']['certificate_issued'], $rows['Failed']['completion_date']]);
        $this->assertSame('In Progress', $rows['Going']['completion_status']);
        $this->assertSame(['Not Started', null], [$rows['Idle']['completion_status'], $rows['Idle']['start_date']]);
        $this->assertArrayNotHasKey('due_date', $d);
        $this->assertArrayNotHasKey('time_spent', $d);

        foreach (['completed' => 'Done', 'failed' => 'Failed', 'in_progress' => 'Going', 'not_started' => 'Idle'] as $status => $name) {
            $this->assertSame([$name], array_column($this->rows('completion', ['statuses' => [$status]]), 'full_name_en'), $status);
        }
        $this->assertSame(['Done'], array_column($this->rows('completion', ['completed_from' => '2026-05-19', 'completed_to' => '2026-05-21']), 'full_name_en'));
        $this->assertSame(['Done'], array_column($this->rows('completion', ['enrolled_to' => '2026-05-02']), 'full_name_en'));
    }

    public function test_quiz_scores_list_sent_attempts_judged_by_the_pass_rule(): void
    {
        $course = Course::factory()->create();
        $exam = CourseExam::factory()->create(['course_id' => $course->id, 'title' => ['en' => 'Module 2 Quiz', 'ar' => 'اختبار'], 'degree' => 20, 'pass_score' => null]);
        DB::table('course_exam_questions')->insert(['course_exam_id' => $exam->id, 'position' => 1, 'type' => 'mcq', 'score' => 20, 'question_en' => 'Q', 'question_ar' => 'س', 'created_at' => now(), 'updated_at' => now()]);
        $session = CourseSession::factory()->create(['course_id' => $course->id, 'title' => 'Question Design']);
        DB::table('course_session_exams')->insert(['session_id' => $session->id, 'exam_id' => $exam->id, 'position' => 1]);
        $attempt = fn (string $name, ?float $score, ?string $state) => DB::table('user_exams')->insert([
            'user_id' => User::factory()->create(['name_en' => $name])->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'user_degree' => 0, 'total_score' => $score, 'max_score' => 20, 'status' => 'success',
            'submission_status' => $state, 'created_at' => now()->subMinutes(12), 'submitted_at' => $state === 'submitted' ? now() : null, 'updated_at' => now(),
        ]);
        $attempt('Passed', 15, 'submitted');
        $attempt('Failed', 4, 'submitted');      // stored 'success', but 4/20 is below half
        $attempt('Waiting', null, 'submitted');  // an open answer still to score
        $attempt('Opened', 18, 'in_progress');   // never sent

        $rows = collect($this->rows('quiz-scores'))->keyBy('full_name_en');

        $this->assertCount(3, $rows);
        $p = $rows['Passed'];
        $this->assertSame(['Module 2 Quiz', 'Question Design', 1, true, 'Pass', 12], [$p['quiz_name'], $p['module_lesson'], $p['attempt_no'], $p['best_attempt'], $p['result'], $p['time_taken']]);
        $this->assertEquals([15, 20, 75, 50], [$p['score'], $p['max_score'], $p['score_pct'], $p['passing_score']]);
        $this->assertSame('Fail', $rows['Failed']['result']);
        $this->assertSame(['Pending Review', null], [$rows['Waiting']['result'], $rows['Waiting']['score']]);

        $this->assertSame(['Failed'], array_column($this->rows('quiz-scores', ['results' => ['fail']]), 'full_name_en'));
        $this->assertSame(['Waiting'], array_column($this->rows('quiz-scores', ['results' => ['pending']]), 'full_name_en'));
        $this->assertCount(3, $this->rows('quiz-scores', ['quiz_ids' => [$exam->id]]));
    }

    public function test_assignment_scores_show_lateness_grading_and_feedback(): void
    {
        $course = Course::factory()->create();
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id, 'title' => 'Interview Guide Draft', 'title_ar' => 'مسودة', 'due_date' => '2026-05-22', 'total_score' => 50, 'pass_score' => 30]);
        $grader = Admin::factory()->create(['name' => 'Ahmed Salem']);
        $submit = fn (string $name, ?float $score, string $sentAt, ?int $reviewer = null) => DB::table('user_course_assignments')->insert([
            'user_id' => User::factory()->create(['name_en' => $name])->id, 'course_assignment_id' => $assignment->id,
            'score' => $score, 'max_score' => 50, 'submitted_at' => $sentAt, 'feedback' => $score ? 'Clear structure' : null,
            'reviewed_by' => $reviewer, 'reviewed_at' => $reviewer ? '2026-05-24 12:00:00' : null, 'created_at' => $sentAt, 'updated_at' => now(),
        ]);
        $submit('OnTime', 42, '2026-05-21 10:00:00', $grader->id);
        $submit('Late', 20, '2026-05-23 09:00:00', $grader->id);
        $submit('Waiting', null, '2026-05-22 23:00:00');

        $rows = collect($this->rows('assignment-scores'))->keyBy('full_name_en');

        $o = $rows['OnTime'];
        $this->assertSame(['Interview Guide Draft', '2026-05-22', '2026-05-21 10:00', false, 'Pass', 'Ahmed Salem', '2026-05-24', 'Clear structure'],
            [$o['assignment_name'], $o['due_date'], $o['submission_date'], $o['late'], $o['result'], $o['graded_by'], $o['graded_date'], $o['feedback']]);
        $this->assertEquals([42, 50, 84, 60], [$o['grade'], $o['max_grade'], $o['grade_pct'], $o['pass_mark']]);
        $this->assertSame([true, 'Fail'], [$rows['Late']['late'], $rows['Late']['result']]);
        $this->assertSame(['Pending Grading', false, null], [$rows['Waiting']['result'], $rows['Waiting']['late'], $rows['Waiting']['graded_by']]);
        $this->assertArrayNotHasKey('resubmissions', $o);
        $this->assertSame(['Late'], array_column($this->rows('assignment-scores', ['results' => ['fail']]), 'full_name_en'));
        $this->assertCount(2, $this->rows('assignment-scores', ['grader_ids' => [$grader->id]]));
        $this->assertSame('مسودة', $this->rows('assignment-scores', [], 'ar')[0]['assignment_name']);
    }

    public function test_certificate_status_lists_valid_and_revoked_with_the_issuer(): void
    {
        $course = Course::factory()->create();
        $course->setTranslations('title', ['en' => 'Structured Interviewing', 'ar' => 'المقابلات المنظمة'])->save();
        $admin = Admin::factory()->create(['name' => 'Mona Adel']);
        $valid = UserCertificate::factory()->create(['course_id' => $course->id, 'issued_by' => $admin->id, 'metadata' => ['course_title' => ['en' => 'Interviewing Certificate', 'ar' => 'شهادة المقابلات']]]);
        $revoked = UserCertificate::factory()->create(['course_id' => $course->id, 'issued_by' => null]);
        app(CertificateService::class)->revoke($revoked);
        // An enrolment alone is not a certificate.
        $this->enrol(User::factory()->create(), $course);

        $rows = collect($this->rows('certificate-status'))->keyBy('certificate_no');

        $this->assertCount(2, $rows);
        $v = $rows[$valid->certificate_number];
        $this->assertSame(['Interviewing Certificate', 'Structured Interviewing', 'Valid', 'Mona Adel'], [$v['certificate_name'], $v['linked_course'], $v['certificate_status'], $v['issued_by']]);
        $r = $rows[$revoked->fresh()->certificate_number];
        $this->assertSame(['Revoked', 'Issued automatically'], [$r['certificate_status'], $r['issued_by']]);
        $this->assertSame([$revoked->fresh()->certificate_number], array_column($this->rows('certificate-status', ['statuses' => ['revoked']]), 'certificate_no'));
        $this->assertSame('شهادة المقابلات', collect($this->rows('certificate-status', [], 'ar'))->firstWhere('certificate_no', $valid->certificate_number)['certificate_name']);
    }
}
