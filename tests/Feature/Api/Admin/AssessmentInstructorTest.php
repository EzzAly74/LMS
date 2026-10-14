<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\Instructor;
use App\Models\User;
use App\Models\UserExam;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-6291: a quiz's or assignment's instructor is its course's instructor
 * (who teaches and grades it), in the reader's language - on every
 * submission, in the Instructor filter and when filtering by it. The
 * Dashboard account that typed it in is only the creator.
 */
class AssessmentInstructorTest extends ApiTestCase
{
    private Course $course;
    private Course $other;
    private Instructor $mona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mona = Instructor::query()->create(['name' => ['en' => 'Mona Adel', 'ar' => 'منى عادل'], 'email' => 'mona@academy.test']);
        $omar = Instructor::query()->create(['name' => ['en' => 'Omar Said', 'ar' => 'عمر سعيد'], 'email' => 'omar@academy.test']);
        $this->course = Course::factory()->create();
        $this->other = Course::factory()->create();
        $this->course->instructors()->attach($this->mona->id);
        $this->other->instructors()->attach($omar->id);
    }

    private function fetch(string $path, array $h, string $locale = 'en'): array
    {
        return $this->getJson(self::BASE.$path, $h + ['Accept-Language' => $locale])->assertOk()->json('result');
    }

    public function test_assignment_submissions_show_and_filter_by_the_course_instructor(): void
    {
        ['headers' => $h, 'model' => $admin] = $this->adminToken();
        $ids = [];
        foreach ([$this->course, $this->other] as $course) {
            $assignment = CourseAssignment::factory()->create(['course_id' => $course->id, 'created_by' => $admin->id]);
            $ids[$course->id] = DB::table('user_course_assignments')->insertGetId([
                'user_id' => User::factory()->create()->id, 'course_assignment_id' => $assignment->id,
                'score' => 60, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = collect($this->fetch('/admin/assignments/submissions?per_page=50', $h))->keyBy('id');
        $this->assertSame('Mona Adel', $rows[$ids[$this->course->id]]['instructor_name']);
        $this->assertSame('Omar Said', $rows[$ids[$this->other->id]]['instructor_name']);
        $ar = collect($this->fetch('/admin/assignments/submissions?per_page=50', $h, 'ar'))->keyBy('id');
        $this->assertSame('منى عادل', $ar[$ids[$this->course->id]]['instructor_name']);
        $this->assertSame('منى عادل', $this->fetch("/admin/assignments/submissions/{$ids[$this->course->id]}", $h, 'ar')['instructor_name']);

        $options = $this->fetch('/admin/assignments/submissions/filter-options', $h)['instructors'];
        $this->assertEqualsCanonicalizing(['Mona Adel', 'Omar Said'], array_column($options, 'name'));

        $only = $this->fetch('/admin/assignments/submissions?per_page=50&instructor_ids[]='.$this->mona->id, $h);
        $this->assertSame([$ids[$this->course->id]], array_map('intval', array_column($only, 'id')));
    }

    public function test_quiz_submissions_show_and_filter_by_the_course_instructor(): void
    {
        ['headers' => $h, 'model' => $admin] = $this->adminToken();
        $ids = [];
        foreach ([$this->course, $this->other] as $course) {
            $quiz = CourseExam::factory()->create(['course_id' => $course->id, 'created_by' => $admin->id]);
            CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 0, 'type' => 'mcq', 'score' => 5,
                'question' => 'Q', 'question_en' => 'Q', 'options_en' => ['a', 'b'], 'correct_answer_en' => 'a']);
            $ids[$course->id] = UserExam::factory()->create([
                'user_id' => User::factory(), 'course_id' => $course->id, 'exam_id' => $quiz->id,
                'total_score' => 5, 'max_score' => 5, 'submission_status' => 'submitted',
            ])->id;
        }

        $rows = collect($this->fetch('/admin/quizzes/submissions?per_page=50', $h))->keyBy('id');
        $this->assertSame('Mona Adel', $rows[$ids[$this->course->id]]['instructor_name']);
        $ar = collect($this->fetch('/admin/quizzes/submissions?per_page=50', $h, 'ar'))->keyBy('id');
        $this->assertSame('عمر سعيد', $ar[$ids[$this->other->id]]['instructor_name']);

        $options = $this->fetch('/admin/quizzes/submissions/filter-options', $h)['instructors'];
        $this->assertEqualsCanonicalizing(['Mona Adel', 'Omar Said'], array_column($options, 'name'));

        $only = $this->fetch('/admin/quizzes/submissions?per_page=50&instructor_ids[]='.$this->mona->id, $h);
        $this->assertSame([$ids[$this->course->id]], array_map('intval', array_column($only, 'id')));
    }

    public function test_the_creator_is_the_dashboard_account(): void
    {
        ['headers' => $h, 'model' => $admin] = $this->adminToken();
        $assignment = CourseAssignment::factory()->create(['course_id' => $this->course->id, 'created_by' => $admin->id]);

        $this->assertSame((int) $admin->id, (int) $this->fetch("/admin/assignments/{$assignment->id}", $h)['created_by_user']['id']);
    }
}
