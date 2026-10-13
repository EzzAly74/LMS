<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\CourseExam;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\CourseSession;
use Illuminate\Support\Facades\DB;

/**
 * Mobile Course Details (Figma 2286:167907) and Schedule (2286:168110):
 * "Course Content" lists modules, quizzes and assignments; the next-cohort
 * card has its round and session count; dates come from the session rows;
 * generated session titles are in the reader's language.
 */
class CourseDetailFigmaTest extends MobileTestCase
{
    private function quiz(Course $course, array $attributes = []): CourseExam
    {
        $quiz = CourseExam::factory()->create(['course_id' => $course->id, 'duration' => 20] + $attributes);
        DB::table('course_exam_questions')->insert([
            'course_exam_id' => $quiz->id, 'question' => json_encode(['en' => 'Q', 'ar' => 'س']), 'question_en' => 'Q',
            'type' => 'mcq', 'score' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $quiz;
    }

    private function assignment(Course $course, array $attributes = []): CourseAssignment
    {
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id] + $attributes);
        CourseAssignmentQuestion::create(['course_assignment_id' => $assignment->id, 'position' => 0, 'type' => 'open', 'score' => 10, 'question_en' => 'Why?']);

        return $assignment;
    }

    private function detail(Course $course, string $locale = 'en')
    {
        $response = $this->withHeaders($this->headersFor($this->employee(), $locale))
            ->getJson(self::BASE.'/mobile/academy/courses/'.$course->id);
        $this->assertSuccess($response);

        return $response;
    }

    public function test_course_content_lists_modules_then_published_quizzes_then_assignments(): void
    {
        $course = Course::factory()->create();
        $video = CourseLecture::factory()->create(['course_id' => $course->id, 'content_type' => 'video', 'duration_minutes' => 15]);
        $doc = CourseLecture::factory()->create(['course_id' => $course->id, 'content_type' => 'document', 'duration_minutes' => null]);
        $quiz = $this->quiz($course);
        $assignment = $this->assignment($course);
        // Never shown: a draft, a quiz without questions, one for specific cohorts.
        $this->quiz($course, ['status' => 'draft']);
        CourseExam::factory()->create(['course_id' => $course->id]);
        $this->quiz($course, ['cohort_scope' => 'specific']);
        $this->assignment($course, ['status' => 'draft']);

        $content = $this->detail($course)->json('result.content');

        $this->assertSame(
            [['lecture', $video->id, 'video', 15], ['lecture', $doc->id, 'document', null], ['quiz', $quiz->id, 'quiz', 20], ['assignment', $assignment->id, 'assignment', null]],
            array_map(fn ($i) => [$i['kind'], $i['id'], $i['content_type'], $i['duration_minutes']], $content),
        );
        $this->assertSame('quiz-'.$quiz->id, $content[2]['key']);
        $this->assertSame('enums.unit_content_type.assignment', $content[3]['label_key']);
        $this->assertSame(4, $this->detail($course)->json('result.content_count'));
        // `units` stays modules only (the schedule's content_ids point at them).
        $this->assertCount(2, $this->detail($course)->json('result.units'));
    }

    public function test_the_next_cohort_card_has_its_round_session_count_and_session_dates(): void
    {
        $course = Course::factory()->create();
        $first = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subMonths(3)->toDateString()]);
        $second = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subMonth()->toDateString()]);
        // Stored dates that drifted from the schedule.
        $next = CourseSection::factory()->create([
            'course_id' => $course->id, 'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(40)->toDateString(),
        ]);
        foreach ([10, 12, 20] as $day) {
            CourseSession::factory()->create([
                'course_id' => $course->id, 'section_id' => $next->id, 'title' => 'Session 9',
                'session_date' => now()->addDays($day)->toDateString(), 'time_from' => '09:00:00', 'time_to' => '11:00:00', 'location' => 'Hybrid · HQ Auditorium',
            ]);
        }

        $response = $this->detail($course, 'ar');
        $cohorts = collect($response->json('result.cohorts'))->keyBy('id');

        $this->assertSame([1, 2, 3], [$cohorts[$first->id]['round'], $cohorts[$second->id]['round'], $cohorts[$next->id]['round']]);
        $card = $cohorts[$next->id];
        $this->assertSame(3, $card['sessions_count']);
        $this->assertSame(now()->addDays(10)->toDateString(), $card['start_date']);
        $this->assertSame(now()->addDays(20)->toDateString(), $card['end_date']);
        // Generated titles are numbered by the schedule, in the reader's language.
        $this->assertSame(['الجلسة 1', 'الجلسة 2', 'الجلسة 3'], array_column($card['sessions'], 'title'));
        $this->assertSame([120, 'upcoming', 'Hybrid · HQ Auditorium'], [$card['sessions'][0]['duration_minutes'], $card['sessions'][0]['status'], $card['sessions'][0]['location']]);
    }

    public function test_a_visitor_never_sees_a_draft_quiz_in_the_player_either(): void
    {
        // The course player shares the rule (it used to show drafts).
        $course = Course::factory()->create();
        $published = $this->quiz($course);
        $draft = $this->quiz($course, ['status' => 'draft']);

        $ids = CourseExam::query()->where('course_id', $course->id)->learnerReady()->pluck('id')->all();
        $this->assertSame([$published->id], $ids);
        $this->assertNotContains($draft->id, $ids);
    }
}
