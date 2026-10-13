<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use App\Support\CourseCompletion;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-104 / D-086 — one definition of "completed course", project-wide.
 *
 * Since D-086 the definition is the learner rule (D-082): a certificate course
 * is completed by an active certificate; a course without one by a submitted,
 * passing final exam, the evaluation, or all of its content. The Dashboard
 * used to match exam statuses 'passed' / 'completed', which the application
 * never writes, so every Dashboard figure read 0 (NEW2B-6224, 6229).
 *
 * The codebase carried two at once:
 *
 *   1. `users_courses.updated_at > created_at`  (dashboard, job titles)
 *   2. `user_exams.status IN (passed, completed)` (learner list and detail)
 *
 * The symptom was two screens shown one click apart disagreeing about the same
 * person: a job-title card could report a learner as compliant while the
 * learner detail it drills into reported them as having completed nothing.
 *
 * (1) was never a completion check. `updated_at > created_at` is true as soon
 * as anything touches the enrolment row — a progress tick, a re-save, a bulk
 * column backfill — so it counted activity and inflated every figure built on
 * it. (2) is now the single definition, in App\Support\CourseCompletion.
 *
 * The first test is the important one: it builds exactly the row the old
 * heuristic would have called complete and asserts it is not.
 */
class CourseCompletionDefinitionTest extends ApiTestCase
{
    private JobTitle $jobTitle;

    private QualificationSkill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobTitle = JobTitle::query()->create(['name' => 'Technician '.uniqid()]);
        $this->skill    = QualificationSkill::query()->create(['name' => 'Welding']);
        $this->jobTitle->qualificationSkills()->attach($this->skill->id);
    }

    private function courseGrantingTheSkill(): Course
    {
        $course = Course::factory()->create();

        DB::table('course_qualification_skills')->insert([
            'course_id'              => $course->id,
            'qualification_skill_id' => $this->skill->id,
        ]);

        return $course;
    }

    /** Enrol, and move `updated_at` past `created_at` — the old "completed" signal. */
    private function enrolAndTouch(User $user, Course $course): void
    {
        $created = now()->subDays(10);

        DB::table('users_courses')->insert([
            'user_id'    => $user->id,
            'course_id'  => $course->id,
            'created_at' => $created,
            'updated_at' => $created->copy()->addDays(3),
        ]);
    }

    /** The certificate a course with a certificate is completed by. */
    private function certify(User $user, Course $course, string $status = UserCertificate::STATUS_ACTIVE): void
    {
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => $status]);
    }

    /** A final exam submission on a course WITHOUT a certificate (status success | fail). */
    private function recordFinalExam(User $user, Course $course, string $status, bool $rich = false, string $submission = 'submitted'): void
    {
        $course->forceFill(['certificate' => false, 'is_evaluate' => false])->save();
        $exam = CourseExam::factory()->final()->create(['course_id' => $course->id]);

        if ($rich) {
            DB::table('course_exam_questions')->insert([
                'course_exam_id' => $exam->id,
                'question'       => json_encode(['en' => 'Q', 'ar' => 'س']),
                'question_en'    => 'Q',
                'type'           => 'mcq',
                'score'          => 10,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        DB::table('user_exams')->insert([
            'user_id'           => $user->id,
            'course_id'         => $course->id,
            'exam_id'           => $exam->id,
            'status'            => $status,
            'submission_status' => $submission,
            'submitted_at'      => $submission === 'submitted' ? now()->subDays(2) : null,
            'created_at'        => now()->subDays(2),
            'updated_at'        => now()->subDays(2),
        ]);
    }

    private function learnerRow(User $user, array $headers): array
    {
        $rows = $this->getJson(
            self::BASE.'/admin/job-titles/'.$this->jobTitle->id.'/learners',
            $headers,
        )->assertOk()->json('result');

        return collect($rows)->firstWhere('id', $user->id) ?? [];
    }

    // ------------------------------------------------------- the defect itself

    public function test_a_touched_enrolment_row_is_not_a_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $this->enrolAndTouch($learner, $this->courseGrantingTheSkill());

        ['headers' => $headers] = $this->adminToken();
        $row = $this->learnerRow($learner, $headers);

        $this->assertSame(1, $row['courses']['total'], 'The enrolment should still be counted.');
        $this->assertSame(
            0,
            $row['courses']['completed'],
            'updated_at > created_at means the row was touched, not that the course was finished.'
        );
    }

    public function test_an_active_certificate_completes_a_certificate_course(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->certify($learner, $course);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->learnerRow($learner, $headers);

        $this->assertSame(1, $row['courses']['completed']);
        $this->assertSame(1, $row['qualifications_completed']);
    }

    public function test_a_revoked_certificate_is_not_a_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->certify($learner, $course, UserCertificate::STATUS_REVOKED);

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(0, $this->learnerRow($learner, $headers)['courses']['completed']);
    }

    public function test_a_passed_exam_does_not_complete_a_certificate_course_without_the_certificate(): void
    {
        // D-082: a certificate course is completed by its certificate only.
        $learner = User::factory()->create();
        $course  = $this->courseGrantingTheSkill();
        $exam    = CourseExam::factory()->final()->create(['course_id' => $course->id]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'status' => 'success', 'submission_status' => 'submitted', 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], CourseCompletion::courseIdsFor($learner->id)->all());
    }

    public function test_a_passing_final_exam_alone_is_not_a_completion_the_certificate_is(): void
    {
        // D-094: every course issues a certificate; completion = holding it.
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->recordFinalExam($learner, $course, 'success');

        ['headers' => $headers] = $this->adminToken();
        $this->assertSame(0, $this->learnerRow($learner, $headers)['courses']['completed']);

        \App\Models\UserCertificate::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);
        $this->assertSame(1, $this->learnerRow($learner, $headers)['courses']['completed']);
    }

    public function test_a_failed_exam_is_not_a_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->recordFinalExam($learner, $course, 'fail');

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(0, $this->learnerRow($learner, $headers)['courses']['completed']);
    }

    public function test_an_opened_rich_quiz_attempt_is_not_a_completion(): void
    {
        // B-162: the attempt row carries the column default status 'success'.
        $learner = User::factory()->create();
        $course  = $this->courseGrantingTheSkill();
        $this->recordFinalExam($learner, $course, 'success', rich: true, submission: 'pending');

        $this->assertSame([], CourseCompletion::courseIdsFor($learner->id)->all());
    }

    public function test_a_submitted_rich_quiz_pass_is_not_a_completion_without_the_certificate(): void
    {
        $learner = User::factory()->create();
        $course  = $this->courseGrantingTheSkill();
        $this->recordFinalExam($learner, $course, 'success', rich: true);

        $this->assertSame([], CourseCompletion::courseIdsFor($learner->id)->all());
    }

    public function test_all_content_without_the_certificate_is_not_a_completion(): void
    {
        // D-094: the certificate switch cannot open a completion path of its own.
        $learner = User::factory()->create();
        $course  = $this->courseGrantingTheSkill();
        $course->forceFill(['certificate' => false])->save();

        $lectures = \App\Models\CourseLecture::factory()->count(2)->create(['course_id' => $course->id])->pluck('id');
        foreach ($lectures as $lectureId) {
            DB::table('user_lecture_progress')->insert([
                'user_id' => $learner->id, 'lecture_id' => $lectureId, 'progress' => 100, 'completed' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame([], CourseCompletion::courseIdsFor($learner->id)->all());

        \App\Models\UserCertificate::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);
        $this->assertSame([$course->id], CourseCompletion::courseIdsFor($learner->id)->all());
    }

    // ------------------------------------------------- the two screens agree

    /**
     * The actual B-104 symptom: the job-title roster and the learner detail it
     * links to must report the same number for the same person.
     */
    public function test_the_job_title_roster_and_the_learner_detail_agree(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);

        $done = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $done);
        $this->certify($learner, $done);

        // Enrolled and touched, but never completed.
        $this->enrolAndTouch($learner, $this->courseGrantingTheSkill());

        ['headers' => $headers] = $this->adminToken();

        $rosterCompleted = $this->learnerRow($learner, $headers)['courses']['completed'];

        $detail = $this->getJson(self::BASE.'/admin/learners/'.$learner->id, $headers)
            ->assertOk()
            ->json('result');

        $detailCompleted = $detail['tiles']['completed_courses'] ?? null;

        $this->assertNotNull(
            $detailCompleted,
            'The learner detail payload should expose a completed-course count; '
            .'if the key moved, update this test rather than dropping the check.'
        );

        $this->assertSame(1, $rosterCompleted);
        $this->assertSame(
            $rosterCompleted,
            (int) $detailCompleted,
            'The roster and the detail it drills into must not contradict each other (B-104).'
        );
    }

    // --------------------------------------------------------- the unit itself

    public function test_course_ids_for_returns_only_completed_courses(): void
    {
        $learner = User::factory()->create();

        $done   = $this->courseGrantingTheSkill();
        $failed = $this->courseGrantingTheSkill();

        $this->certify($learner, $done);
        $this->recordFinalExam($learner, $failed, 'fail');

        $this->assertSame([$done->id], CourseCompletion::courseIdsFor($learner->id)->all());
    }

    public function test_a_retake_does_not_create_a_second_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);

        // A certificate and a passing exam for the same course.
        $this->certify($learner, $course);
        $exam = CourseExam::factory()->final()->create(['course_id' => $course->id]);
        DB::table('user_exams')->insert([
            'user_id' => $learner->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'status' => 'success', 'submission_status' => 'submitted', 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(
            1,
            $this->learnerRow($learner, $headers)['courses']['completed'],
            'A course completed twice is still one completed course.'
        );
    }
}
