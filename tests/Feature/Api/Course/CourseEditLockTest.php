<?php

namespace Tests\Feature\Api\Course;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Support\CourseEditLock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * D-095: from the first enrolment a course keeps its type, certificate rule
 * and qualifications. Sending them unchanged still saves; every other field
 * stays editable; the Edit form reads `locked_fields`.
 */
class CourseEditLockTest extends ApiTestCase
{
    private Instructor $instructor;
    private QualificationSkill $qualification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instructor = Instructor::query()->create([
            'name'  => ['en' => 'Teacher', 'ar' => 'معلم'],
            'email' => 'teacher'.uniqid().'@example.test',
        ]);
        $this->qualification = QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات الأولية']]);
    }

    private function course(bool $enrolled): Course
    {
        $course = Course::factory()->create([
            'course_type' => 'hybrid',
            'certificate_custom_rule' => true,
            'certificate_mode' => 'both',
            'certificate_attendance_threshold' => 80,
            'certificate_score_threshold' => 60,
        ]);
        $course->instructors()->sync([$this->instructor->id]);
        $course->qualificationSkills()->sync([$this->qualification->id]);
        if ($enrolled) {
            DB::table('users_courses')->insert([
                'user_id' => User::factory()->create()->id, 'course_id' => $course->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $course;
    }

    /** The form as it would be re-sent unchanged. */
    private function unchanged(array $overrides = []): array
    {
        return array_replace([
            'title' => ['en' => 'Renamed course', 'ar' => 'دورة معدلة'],
            'course_type' => 'hybrid',
            'level' => 'beginner',
            'instructors' => [$this->instructor->id],
            'certificate_rule' => 'both',
            'certificate_min_attendance' => 80,
            'certificate_min_score' => 60,
            'qualification_skill_ids' => [$this->qualification->id],
        ], $overrides);
    }

    private function edit(Course $course, array $payload)
    {
        return $this->withHeaders($this->adminToken()['headers'])
            ->post(self::BASE.'/courses/'.$course->id, ['_method' => 'PUT'] + $payload, ['Accept' => 'application/json']);
    }

    public function test_the_detail_lists_the_locked_fields_only_once_learners_enrolled(): void
    {
        $headers = $this->adminToken()['headers'];

        $this->withHeaders($headers)->getJson(self::BASE.'/courses/'.$this->course(false)->id)
            ->assertOk()->assertJsonPath('result.locked_fields', []);
        $this->withHeaders($headers)->getJson(self::BASE.'/courses/'.$this->course(true)->id)
            ->assertOk()->assertJsonPath('result.locked_fields', CourseEditLock::FIELDS);
    }

    public function test_an_enrolled_course_saves_when_the_locked_values_are_sent_unchanged(): void
    {
        $course = $this->course(true);

        $this->edit($course, $this->unchanged())->assertOk();
        $this->assertSame('Renamed course', $course->fresh()->getTranslation('title', 'en'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function changes(): iterable
    {
        yield 'course type'         => [['course_type' => 'online'], 'course_type'];
        yield 'rule'                => [['certificate_rule' => 'general'], 'certificate_rule'];
        yield 'attendance minimum'  => [['certificate_min_attendance' => 50], 'certificate_min_attendance'];
        yield 'score minimum'       => [['certificate_min_score' => 90], 'certificate_min_score'];
        yield 'qualification removed' => [['qualification_skill_ids' => ''], 'qualification_skill_ids'];
    }

    /** @dataProvider changes */
    public function test_an_enrolled_course_refuses_a_change_to_a_locked_value(array $change, string $field): void
    {
        $course = $this->course(true);

        $this->edit($course, $this->unchanged($change))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);

        $fresh = $course->fresh();
        $this->assertSame('hybrid', $fresh->course_type);
        $this->assertSame('both', $fresh->certificate_mode);
        $this->assertSame(80, $fresh->certificate_attendance_threshold);
        $this->assertSame([$this->qualification->id], $fresh->qualificationSkills()->pluck('qualification_skills.id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotSame('Renamed course', $fresh->getTranslation('title', 'en'), 'nothing is saved');
    }

    public function test_an_added_qualification_is_refused_too(): void
    {
        $other = QualificationSkill::query()->create(['name' => ['en' => 'Fire Safety', 'ar' => 'السلامة من الحرائق']]);

        $this->edit($this->course(true), $this->unchanged(['qualification_skill_ids' => [$this->qualification->id, $other->id]]))
            ->assertStatus(422)->assertJsonValidationErrors(['qualification_skill_ids']);
    }

    /** @dataProvider changes */
    public function test_a_course_nobody_enrolled_in_can_change_anything(array $change): void
    {
        $this->edit($this->course(false), $this->unchanged($change))->assertOk();
    }

    public function test_the_other_fields_stay_editable_after_enrolment(): void
    {
        $course = $this->course(true);
        $other = Instructor::query()->create(['name' => ['en' => 'Other', 'ar' => 'آخر'], 'email' => 'o'.uniqid().'@example.test']);

        $this->edit($course, $this->unchanged([
            'level' => 'professional',
            'instructors' => [$other->id],
            'description' => ['en' => 'New text', 'ar' => 'نص جديد'],
        ]))->assertOk();

        $fresh = $course->fresh();
        $this->assertSame('professional', $fresh->level);
        $this->assertSame([$other->id], $fresh->instructors()->pluck('instructors.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_payload_without_the_locked_fields_is_fine(): void
    {
        $payload = $this->unchanged();
        unset($payload['course_type'], $payload['certificate_rule'], $payload['certificate_min_attendance'],
            $payload['certificate_min_score'], $payload['qualification_skill_ids']);

        $this->edit($this->course(true), $payload)->assertOk();
    }
}
