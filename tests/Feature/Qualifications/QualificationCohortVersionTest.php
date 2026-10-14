<?php

namespace Tests\Feature\Qualifications;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use App\Support\CourseQualifications;
use App\Support\LearnerCompliance;
use App\Support\QualificationHolding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NEW2B-6049 (D-106): changing a course's qualifications applies from the
 * next cohort; cohorts under way keep the list they started with, in every
 * holding / progress / compliance calculation.
 */
class QualificationCohortVersionTest extends TestCase
{
    use RefreshDatabase;

    private QualificationSkill $firstAid;
    private QualificationSkill $fireSafety;
    private Course $course;
    private JobTitle $jobTitle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firstAid = QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات الأولية']]);
        $this->fireSafety = QualificationSkill::query()->create(['name' => ['en' => 'Fire Safety', 'ar' => 'السلامة من الحرائق']]);
        $this->course = Course::factory()->create();
        $this->course->qualificationSkills()->sync([$this->firstAid->id]);
        $this->jobTitle = JobTitle::factory()->create();
        $this->jobTitle->qualificationSkills()->sync([$this->firstAid->id, $this->fireSafety->id]);
    }

    private function learnerIn(CourseSection $cohort, bool $completed): User
    {
        $user = User::factory()->create(['job_title_id' => $this->jobTitle->id]);
        DB::table('users_courses')->insert([
            'user_id' => $user->id, 'course_id' => $this->course->id, 'group_id' => $cohort->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($completed) {
            UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $this->course->id]);
        }

        return $user;
    }

    private function changeQualificationsTo(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            CourseQualifications::freezeCohortsBeforeChange($this->course, $ids);
            $this->course->qualificationSkills()->sync($ids);
        });
    }

    private function holdsSql(User $user, QualificationSkill $q): bool
    {
        $sql = QualificationHolding::holdsSql((string) (int) $user->id, (string) (int) $q->id);

        return (bool) DB::selectOne("SELECT CASE WHEN {$sql} THEN 1 ELSE 0 END AS h")->h;
    }

    public function test_the_cohort_under_way_keeps_its_qualifications_and_the_next_one_gets_the_new_list(): void
    {
        $running = CourseSection::factory()->running()->create(['course_id' => $this->course->id]);
        $future = CourseSection::factory()->create(['course_id' => $this->course->id, 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString()]);
        $old = $this->learnerIn($running, true);

        $this->changeQualificationsTo([$this->fireSafety->id]);

        // Only the cohort under way is frozen; the empty future one follows the course.
        $this->assertNotNull($running->fresh()->qualifications_frozen_at);
        $this->assertNull($future->fresh()->qualifications_frozen_at);

        $next = $this->learnerIn($future, true);

        // SQL rule (job-title cards, qualification pages).
        $this->assertTrue($this->holdsSql($old, $this->firstAid), 'the old cohort keeps First Aid');
        $this->assertFalse($this->holdsSql($old, $this->fireSafety), 'and does not get Fire Safety');
        $this->assertTrue($this->holdsSql($next, $this->fireSafety), 'the next cohort gets Fire Safety');
        $this->assertFalse($this->holdsSql($next, $this->firstAid));

        // PHP rule (learners list, profile): the same answer.
        $map = CourseQualifications::coursesByQualification([$old->id, $next->id], [$this->firstAid->id, $this->fireSafety->id]);
        $this->assertSame([$this->course->id], $map[$old->id][$this->firstAid->id]);
        $this->assertSame([], $map[$old->id][$this->fireSafety->id]);
        $this->assertSame([], $map[$next->id][$this->firstAid->id]);
        $this->assertSame([$this->course->id], $map[$next->id][$this->fireSafety->id]);

        $compliance = LearnerCompliance::forUsers([$old->id, $next->id]);
        $this->assertSame(50, $compliance[$old->id]['compliance'], 'First Aid held, Fire Safety not');
        $this->assertSame(50, $compliance[$next->id]['compliance'], 'Fire Safety held, First Aid not');

        $this->assertSame([$this->course->id => [$this->firstAid->id]], CourseQualifications::forUserCourses($old->id, [$this->course->id]));
        $this->assertSame([$this->course->id => [$this->fireSafety->id]], CourseQualifications::forUserCourses($next->id, [$this->course->id]));
    }

    public function test_a_second_change_does_not_move_an_already_frozen_cohort(): void
    {
        $running = CourseSection::factory()->running()->create(['course_id' => $this->course->id]);
        $this->learnerIn($running, false);

        $this->changeQualificationsTo([$this->fireSafety->id]);
        $this->changeQualificationsTo([$this->firstAid->id, $this->fireSafety->id]);

        $this->assertSame([$this->firstAid->id], DB::table('cohort_qualification_skills')->where('section_id', $running->id)
            ->pluck('qualification_skill_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_saving_the_same_list_freezes_nothing(): void
    {
        $running = CourseSection::factory()->running()->create(['course_id' => $this->course->id]);
        $this->learnerIn($running, false);

        $this->changeQualificationsTo([$this->firstAid->id]);

        $this->assertNull($running->fresh()->qualifications_frozen_at);
        $this->assertSame(0, DB::table('cohort_qualification_skills')->count());
    }
}
