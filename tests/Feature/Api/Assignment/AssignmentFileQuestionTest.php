<?php

namespace Tests\Feature\Api\Assignment;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\User;
use App\Models\UserCourseAssignmentAnswer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiTestCase;

/**
 * File-type assignment questions (D-033, D-064; Figma 2393:120281 /
 * 2393:121481 / 2393:121817) plus the defects found building them:
 * B-128 (editing erased answers), B-129 (pending shown as graded),
 * B-130 (no enrolment check on the learner flow).
 */
class AssignmentFileQuestionTest extends ApiTestCase
{
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
        $this->course = Course::factory()->create();
    }

    /** A valid PDF: real magic bytes, so finfo reports application/pdf. */
    private function pdf(string $name = 'answer.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function payload(array $questions): array
    {
        return [
            'course_id'    => $this->course->id,
            'title'        => 'Practical Assessment',
            'cohort_scope' => 'all',
            'status'       => 'active',
            'questions'    => $questions,
        ];
    }

    private function fileQuestion(array $extra = []): array
    {
        return $extra + ['type' => 'file', 'score' => 20, 'question_en' => 'Describe the first three actions after a spill.'];
    }

    /** @return array{0: CourseAssignment, 1: CourseAssignmentQuestion} */
    private function assignmentWithFileQuestion(): array
    {
        ['headers' => $headers] = $this->adminToken();
        $id = $this->postJson(self::BASE.'/admin/assignments', $this->payload([$this->fileQuestion()]), $headers)
            ->assertSuccessful()->json('result.id');
        $assignment = CourseAssignment::findOrFail($id);

        return [$assignment, $assignment->questions()->firstOrFail()];
    }

    /** @return array{0: User, 1: array<string,string>} */
    private function enrolledLearner(): array
    {
        $user = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $this->course->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $this->userToken($user)['headers']];
    }

    private function answerUrl(CourseAssignment $a, CourseAssignmentQuestion $q): string
    {
        return self::BASE."/courses/{$this->course->id}/assignments/{$a->id}/questions/{$q->id}/answer";
    }

    // ------------------------------------------------------------ authoring

    public function test_an_admin_creates_a_file_question_without_a_correct_answer(): void
    {
        [, $question] = $this->assignmentWithFileQuestion();

        $this->assertSame('file', $question->type);
        $this->assertNull($question->correct_answer_en);
    }

    public function test_an_admin_attaches_a_file_to_a_file_question_privately(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        ['headers' => $headers] = $this->adminToken();

        $res = $this->post(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment", ['file' => $this->pdf('brief.pdf')], $headers + ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('brief.pdf', $res->json('result.name'));
        $this->assertStringNotContainsString('AssignmentAttachment', (string) json_encode($res->json()));
        $q->refresh();
        Storage::disk('private')->assertExists($q->attachment_path);
        $this->assertStringEndsWith('.pdf', $q->attachment_path);

        $this->get($res->json('result.download_url'), $headers)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('brief.pdf');
    }

    public function test_a_disguised_script_is_refused_as_an_attachment(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        ['headers' => $headers] = $this->adminToken();
        // A real temporary file, so finfo reads the contents: a fake upload
        // reports the type its name implies and would prove nothing.
        $tmp = tempnam(sys_get_temp_dir(), 'shell');
        file_put_contents($tmp, '<?php echo "x"; ?>');
        $shell = new UploadedFile($tmp, 'brief.pdf', null, null, true);

        $this->post(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment", ['file' => $shell], $headers + ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_attachment_needs_a_file_question_of_that_assignment(): void
    {
        [$a] = $this->assignmentWithFileQuestion();
        ['headers' => $headers] = $this->adminToken();
        $other = CourseAssignmentQuestion::create(['course_assignment_id' => $a->id, 'position' => 1, 'type' => 'open', 'score' => 5, 'question_en' => 'Why?']);
        $elsewhere = CourseAssignment::create(['course_id' => $this->course->id, 'title' => 'Other', 'cohort_scope' => 'all', 'status' => 'active']);

        $this->post(self::BASE."/admin/assignments/{$a->id}/questions/{$other->id}/attachment", ['file' => $this->pdf()], $headers + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post(self::BASE."/admin/assignments/{$elsewhere->id}/questions/{$other->id}/attachment", ['file' => $this->pdf()], $headers + ['Accept' => 'application/json'])->assertStatus(404);
    }

    // ------------------------------------------------------------ B-128

    public function test_editing_an_assignment_keeps_its_learners_answers_and_grades(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf()], $learner + ['Accept' => 'application/json'])->assertOk();
        $answer = UserCourseAssignmentAnswer::firstOrFail();
        ['headers' => $admin] = $this->adminToken();
        $this->putJson(self::BASE."/admin/assignments/submissions/{$answer->user_course_assignment_id}/answers/{$answer->id}/grade", ['awarded_score' => 12], $admin)->assertOk();

        // A typo fix, sending the question back with its id.
        $this->putJson(self::BASE."/admin/assignments/{$a->id}", $this->payload([$this->fileQuestion(['id' => $q->id, 'question_en' => 'Describe the first three actions after a spill hazard.'])]), $admin)
            ->assertOk();

        $answer->refresh();
        $this->assertSame(12, $answer->awarded_score);
        $this->assertSame($q->id, $answer->course_assignment_question_id);
        Storage::disk('private')->assertExists($answer->file_path);
    }

    public function test_a_question_id_from_another_assignment_is_refused(): void
    {
        [$a] = $this->assignmentWithFileQuestion();
        [, $foreign] = $this->assignmentWithFileQuestion();
        ['headers' => $admin] = $this->adminToken();

        $this->putJson(self::BASE."/admin/assignments/{$a->id}", $this->payload([$this->fileQuestion(['id' => $foreign->id])]), $admin)
            ->assertStatus(422)
            ->assertJsonValidationErrors('questions.0.id');
        $this->assertSame($foreign->course_assignment_id, $foreign->fresh()->course_assignment_id);
    }

    public function test_a_question_left_out_is_removed_with_its_files(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf()], $learner + ['Accept' => 'application/json'])->assertOk();
        ['headers' => $admin] = $this->adminToken();

        $this->putJson(self::BASE."/admin/assignments/{$a->id}", $this->payload([['type' => 'open', 'score' => 5, 'question_en' => 'Instead']]), $admin)->assertOk();

        $this->assertNull($q->fresh());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    // ------------------------------------------------------------ learner

    public function test_an_enrolled_learner_uploads_a_file_answer_privately(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();

        $res = $this->post($this->answerUrl($a, $q), ['file' => $this->pdf('my-work.pdf')], $learner + ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertTrue($res->json('result.pending'));
        $this->assertSame('my-work.pdf', $res->json('result.my_file.name'));
        $answer = UserCourseAssignmentAnswer::firstOrFail();
        Storage::disk('private')->assertExists($answer->file_path);
        Storage::disk('public')->assertMissing($answer->file_path);

        $this->get($res->json('result.my_file.download_url'), $learner)->assertOk()->assertDownload('my-work.pdf');
    }

    public function test_the_file_can_be_replaced_until_graded_then_it_is_locked(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf('first.pdf')], $learner + ['Accept' => 'application/json'])->assertOk();
        $first = UserCourseAssignmentAnswer::firstOrFail()->file_path;

        // The only question is answered, so the attempt is already submitted:
        // a file may still be replaced while ungraded.
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf('second.pdf')], $learner + ['Accept' => 'application/json'])->assertOk();
        $answer = UserCourseAssignmentAnswer::firstOrFail();
        $this->assertSame('second.pdf', $answer->file_name);
        Storage::disk('private')->assertMissing($first);

        ['headers' => $admin] = $this->adminToken();
        $this->putJson(self::BASE."/admin/assignments/submissions/{$answer->user_course_assignment_id}/answers/{$answer->id}/grade", ['awarded_score' => 15], $admin)->assertOk();

        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf('third.pdf')], $learner + ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame('second.pdf', $answer->fresh()->file_name);
    }

    public function test_a_wrong_file_type_is_refused(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();

        $this->post($this->answerUrl($a, $q), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text')], $learner + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($this->answerUrl($a, $q), ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], $learner + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_another_learner_cannot_download_someone_elses_answer(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        [, $learner] = $this->enrolledLearner();
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf()], $learner + ['Accept' => 'application/json'])->assertOk();
        [, $other] = $this->enrolledLearner();

        $this->getJson(self::BASE."/courses/{$this->course->id}/assignments/{$a->id}/questions/{$q->id}/my-file", $other)->assertStatus(404);
    }

    // ------------------------------------------------------------ B-130

    public function test_a_learner_not_enrolled_in_the_course_is_refused(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        ['headers' => $stranger] = $this->userToken();

        $this->getJson(self::BASE."/courses/{$this->course->id}/assignments/{$a->id}/take", $stranger)->assertStatus(403);
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf()], $stranger + ['Accept' => 'application/json'])->assertStatus(403);
        $this->assertSame(0, UserCourseAssignmentAnswer::count());
    }

    // ------------------------------------------------------------ admin review + B-129

    public function test_the_admin_detail_shows_both_files_and_stays_pending_until_graded(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        ['headers' => $admin] = $this->adminToken();
        $this->post(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment", ['file' => $this->pdf('brief.pdf')], $admin + ['Accept' => 'application/json'])->assertOk();
        [, $learner] = $this->enrolledLearner();
        $this->post($this->answerUrl($a, $q), ['file' => $this->pdf('my-work.pdf')], $learner + ['Accept' => 'application/json'])->assertOk();
        $answer = UserCourseAssignmentAnswer::firstOrFail();
        $sid = $answer->user_course_assignment_id;

        $detail = $this->getJson(self::BASE."/admin/assignments/submissions/{$sid}", $admin)->assertOk()->json('result');
        $this->assertSame('pending', $detail['status']);
        $this->assertNull($detail['score_percent']);
        $this->assertSame('my-work.pdf', $detail['answers'][0]['file']['name']);
        $this->assertSame('brief.pdf', $detail['answers'][0]['question']['attachment']['name']);
        $this->assertArrayNotHasKey('file_path', $detail['answers'][0]);
        $this->get($detail['answers'][0]['file']['download_url'], $admin)->assertOk()->assertDownload('my-work.pdf');

        $list = fn (string $status) => collect($this->getJson(self::BASE."/admin/assignments/submissions?status={$status}", $admin)->assertOk()->json('result'))->pluck('id')->all();
        $this->assertSame([$sid], $list('pending'));
        $this->assertSame([], $list('graded'));

        $this->putJson(self::BASE."/admin/assignments/submissions/{$sid}/answers/{$answer->id}/grade", ['awarded_score' => 10], $admin)
            ->assertOk()
            ->assertJsonPath('result.submission.status', 'graded')
            ->assertJsonPath('result.submission.score_percent', 50);
        $this->assertSame([$sid], $list('graded'));
        $this->assertSame([], $list('pending'));
    }

    public function test_an_admin_without_the_permission_cannot_read_files(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();
        $role = \Spatie\Permission\Models\Role::findOrCreate('asg-restricted', 'admin');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = \App\Models\Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->get(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment", $headers)->assertStatus(403);
        $this->post(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment", ['file' => $this->pdf()], $headers)->assertStatus(403);
    }

    public function test_a_guest_cannot_download(): void
    {
        [$a, $q] = $this->assignmentWithFileQuestion();

        $this->getJson(self::BASE."/admin/assignments/{$a->id}/questions/{$q->id}/attachment")->assertStatus(401);
        $this->getJson(self::BASE."/courses/{$this->course->id}/assignments/{$a->id}/questions/{$q->id}/attachment")->assertStatus(401);
    }
}
