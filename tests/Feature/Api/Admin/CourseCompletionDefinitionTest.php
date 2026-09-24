<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Support\CourseCompletion;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-104 — one definition of "completed course", project-wide.
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

    private function recordExam(User $user, Course $course, string $status): void
    {
        $exam = CourseExam::factory()->create(['course_id' => $course->id]);

        DB::table('user_exams')->insert([
            'user_id'      => $user->id,
            'course_id'    => $course->id,
            'exam_id'      => $exam->id,
            'status'       => $status,
            'submitted_at' => now()->subDays(2),
            'created_at'   => now()->subDays(2),
            'updated_at'   => now()->subDays(2),
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

    public function test_a_passing_exam_is_a_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->recordExam($learner, $course, 'passed');

        ['headers' => $headers] = $this->adminToken();
        $row = $this->learnerRow($learner, $headers);

        $this->assertSame(1, $row['courses']['completed']);
        $this->assertSame(1, $row['qualifications_completed']);
    }

    public function test_a_failed_exam_is_not_a_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->recordExam($learner, $course, 'failed');

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(0, $this->learnerRow($learner, $headers)['courses']['completed']);
    }

    public function test_the_status_match_is_case_insensitive(): void
    {
        // The column is free-form text and the existing data is mixed case.
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);
        $this->recordExam($learner, $course, 'Passed');

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(1, $this->learnerRow($learner, $headers)['courses']['completed']);
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
        $this->recordExam($learner, $done, 'completed');

        // Enrolled and touched, but never passed.
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

    public function test_course_ids_for_returns_only_passed_courses(): void
    {
        $learner = User::factory()->create();

        $passed = $this->courseGrantingTheSkill();
        $failed = $this->courseGrantingTheSkill();

        $this->recordExam($learner, $passed, 'passed');
        $this->recordExam($learner, $failed, 'failed');

        $ids = CourseCompletion::courseIdsFor($learner->id)->all();

        $this->assertSame([$passed->id], $ids);
    }

    public function test_a_retake_does_not_create_a_second_completion(): void
    {
        $learner = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        $course  = $this->courseGrantingTheSkill();
        $this->enrolAndTouch($learner, $course);

        // Two passing records for the same course - a retake, or a re-review.
        $this->recordExam($learner, $course, 'passed');
        $this->recordExam($learner, $course, 'completed');

        ['headers' => $headers] = $this->adminToken();

        $this->assertSame(
            1,
            $this->learnerRow($learner, $headers)['courses']['completed'],
            'A course completed twice is still one completed course.'
        );
    }
}
