<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseSession;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-137: "Specific cohort" on a quiz or assignment listed and stored class
 * SESSIONS ("session 1", "session 2") instead of the course's COHORTS
 * (course_sections: "First Group", "Second Group").
 */
class AssessmentCohortsTest extends ApiTestCase
{
    private Course $course;
    private CourseSection $first;
    private CourseSection $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create();
        $this->first = CourseSection::factory()->create(['course_id' => $this->course->id, 'name' => ['en' => 'First Group', 'ar' => 'المجموعة الأولى']]);
        $this->second = CourseSection::factory()->create(['course_id' => $this->course->id, 'name' => ['en' => 'Second Group', 'ar' => 'المجموعة الثانية']]);
        // Class sessions inside the cohorts: these are not cohorts.
        CourseSession::factory()->create(['course_id' => $this->course->id, 'section_id' => $this->first->id, 'title' => 'session 1']);
        CourseSession::factory()->create(['course_id' => $this->course->id, 'section_id' => $this->first->id, 'title' => 'session 2']);
    }

    private function payload(array $cohortIds, ?int $courseId = null): array
    {
        return [
            'course_id' => $courseId ?? $this->course->id, 'title' => 'Check', 'title_ar' => 'فحص', 'type' => 'pre',
            'cohort_scope' => 'specific', 'cohort_ids' => $cohortIds, 'status' => 'active',
            'questions' => [['type' => 'open', 'score' => 5, 'question_en' => 'Why', 'question_ar' => 'لماذا']],
        ];
    }

    public function test_the_picker_lists_the_course_cohorts_not_its_sessions(): void
    {
        ['headers' => $h] = $this->adminToken();
        Course::factory()->create(); // another course's cohorts must not appear

        foreach (['assignments', 'quizzes'] as $kind) {
            $rows = $this->getJson(self::BASE."/admin/$kind/cohorts?course_id={$this->course->id}", $h)->assertOk()->json('result');
            $this->assertSame(['First Group', 'Second Group'], array_column($rows, 'title'));
            $this->assertSame([$this->first->id, $this->second->id], array_column($rows, 'id'));
        }
    }

    public function test_the_picker_needs_a_course(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/assignments/cohorts', $h)->assertStatus(422)->assertJsonValidationErrors('course_id');
        $this->getJson(self::BASE.'/admin/quizzes/cohorts', $h)->assertStatus(422)->assertJsonValidationErrors('course_id');
    }

    public function test_a_specific_cohort_is_stored_and_shown_by_name(): void
    {
        ['headers' => $h] = $this->adminToken();

        foreach (['assignments' => 'course_assignment_cohorts', 'quizzes' => 'course_exam_cohorts'] as $kind => $table) {
            $id = $this->postJson(self::BASE."/admin/$kind", $this->payload([$this->second->id]), $h)->assertSuccessful()->json('result.id');

            $this->assertSame([$this->second->id], DB::table($table)->pluck('course_section_id')->map(fn ($v) => (int) $v)->all());
            $this->getJson(self::BASE."/admin/$kind/$id", $h)->assertOk()
                ->assertJsonPath('result.cohorts.0.id', $this->second->id)
                ->assertJsonPath('result.cohorts.0.title', 'Second Group');
        }
    }

    public function test_a_session_id_or_another_courses_cohort_is_refused(): void
    {
        ['headers' => $h] = $this->adminToken();
        $session = CourseSession::query()->firstOrFail();
        $foreign = CourseSection::factory()->create(['course_id' => Course::factory()->create()->id]);

        foreach (['assignments', 'quizzes'] as $kind) {
            $this->postJson(self::BASE."/admin/$kind", $this->payload([$foreign->id]), $h)
                ->assertStatus(422)->assertJsonValidationErrors('cohort_ids.0');
            if (! CourseSection::whereKey($session->id)->where('course_id', $this->course->id)->exists()) {
                $this->postJson(self::BASE."/admin/$kind", $this->payload([$session->id]), $h)
                    ->assertStatus(422)->assertJsonValidationErrors('cohort_ids.0');
            }
        }
    }
}
