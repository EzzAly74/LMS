<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\User;
use App\Models\UserCourseAssignment;
use App\Models\UserExam;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-6357: the Quizzes / Assignments lists find a quiz or assignment by
 * its own name or its course's name, in the created table, the View All
 * dialog and the attempts table (with the learner's name), and the attempts
 * table filters by quiz / assignment.
 */
class AssessmentSearchTest extends ApiTestCase
{
    private Course $safety;
    private Course $leadership;
    private CourseExam $fireQuiz;
    private CourseExam $teamQuiz;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        ['headers' => $this->headers] = $this->adminToken();
        $this->safety = Course::factory()->create(['title' => ['en' => 'Workplace Safety', 'ar' => 'السلامة المهنية']]);
        $this->leadership = Course::factory()->create(['title' => ['en' => 'Leadership 100%', 'ar' => 'القيادة']]);
        $this->fireQuiz = $this->quiz($this->safety, 'Fire Drill Check', 'اختبار الإخلاء');
        $this->teamQuiz = $this->quiz($this->leadership, 'Team Roles', 'أدوار الفريق');
    }

    private function quiz(Course $course, string $en, string $ar): CourseExam
    {
        $quiz = CourseExam::factory()->create(['course_id' => $course->id, 'section_id' => null, 'title' => ['en' => $en, 'ar' => $ar], 'title_ar' => $ar]);
        CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 0, 'type' => 'mcq', 'score' => 5,
            'question' => 'Q', 'question_en' => 'Q', 'options_en' => ['a', 'b'], 'correct_answer_en' => 'a']);

        return $quiz;
    }

    private function attempt(CourseExam $quiz, string $learner): UserExam
    {
        return UserExam::factory()->create([
            'user_id' => User::factory()->create(['name' => $learner, 'name_en' => $learner]), 'course_id' => $quiz->course_id,
            'exam_id' => $quiz->id, 'total_score' => 5, 'max_score' => 5, 'submission_status' => 'submitted',
        ]);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map('intval', array_column($this->getJson(self::BASE.$url, $this->headers)->assertOk()->json('result'), 'id'));
    }

    public function test_the_created_quizzes_are_found_by_quiz_name_or_course_name(): void
    {
        $this->assertSame([$this->fireQuiz->id], $this->ids('/admin/quizzes?search=Fire'));
        $this->assertSame([$this->fireQuiz->id], $this->ids('/admin/quizzes?search=Workplace'));
        $this->assertSame([$this->teamQuiz->id], $this->ids('/admin/quizzes?search='.urlencode('القيادة')));
        $this->assertSame([$this->teamQuiz->id], $this->ids('/admin/quizzes?search='.urlencode('أدوار')));
        // % is a character, not a wildcard.
        $this->assertSame([$this->teamQuiz->id], $this->ids('/admin/quizzes?search='.urlencode('100%')));

        $minimal = array_column($this->getJson(self::BASE.'/admin/quizzes/list?search=Leadership', $this->headers)->assertOk()->json('result'), 'id');
        $this->assertSame([$this->teamQuiz->id], array_map('intval', $minimal));

        // A quiz id that is not a number is not found (was a 500).
        $this->getJson(self::BASE.'/admin/quizzes/minimal', $this->headers)->assertNotFound();
        $this->getJson(self::BASE.'/admin/assignments/abc', $this->headers)->assertNotFound();
    }

    public function test_attempts_are_found_by_learner_quiz_or_course_and_filtered_by_quiz(): void
    {
        $sara = $this->attempt($this->fireQuiz, 'Sara Ali');
        $omar = $this->attempt($this->teamQuiz, 'Omar Hassan');

        $this->assertSame([$sara->id], $this->ids('/admin/quizzes/submissions?search=Sara'));
        $this->assertSame([$sara->id], $this->ids('/admin/quizzes/submissions?search=Drill'));
        $this->assertSame([$omar->id], $this->ids('/admin/quizzes/submissions?search=Leadership'));
        $this->assertSame([$omar->id], $this->ids('/admin/quizzes/submissions?search='.urlencode('القيادة')));

        $this->assertSame([$sara->id], $this->ids('/admin/quizzes/submissions?quiz_ids[]='.$this->fireQuiz->id));
        $this->getJson(self::BASE.'/admin/quizzes/submissions?quiz_ids[]=abc', $this->headers)->assertStatus(422);
    }

    public function test_assignments_search_and_filter_the_same_way(): void
    {
        $report = CourseAssignment::factory()->create(['course_id' => $this->safety->id, 'title' => 'Hazard Report', 'title_ar' => 'تقرير المخاطر']);
        $plan = CourseAssignment::factory()->create(['course_id' => $this->leadership->id, 'title' => 'Team Plan', 'title_ar' => 'خطة الفريق']);
        foreach ([$report, $plan] as $a) {
            CourseAssignmentQuestion::create(['course_assignment_id' => $a->id, 'position' => 0, 'type' => 'open', 'score' => 10, 'question_en' => 'Why?']);
        }
        $this->assertSame([$report->id], $this->ids('/admin/assignments?search=Workplace'));
        $this->assertSame([$plan->id], $this->ids('/admin/assignments?search=Plan'));

        $sent = fn (CourseAssignment $a, string $name) => UserCourseAssignment::query()->create([
            'user_id' => User::factory()->create(['name' => $name, 'name_en' => $name])->id,
            'course_assignment_id' => $a->id, 'submitted_at' => now(), 'total_score' => 8,
        ]);
        $first = $sent($report, 'Huda Atef');
        $second = $sent($plan, 'Mona Adel');

        $this->assertSame([$first->id], $this->ids('/admin/assignments/submissions?search=Hazard'));
        $this->assertSame([$second->id], $this->ids('/admin/assignments/submissions?search=Leadership'));
        $this->assertSame([$second->id], $this->ids('/admin/assignments/submissions?search=Mona'));
        $this->assertSame([$first->id], $this->ids('/admin/assignments/submissions?assignment_ids[]='.$report->id));
    }
}
