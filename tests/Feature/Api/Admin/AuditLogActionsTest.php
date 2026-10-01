<?php

namespace Tests\Feature\Api\Admin;

use App\Models\AdminMessage;
use App\Models\AuditLog;
use App\Models\CertificateTemplate;
use App\Models\ExternalTrainingRequest;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Dashboard actions that are not a plain model save still reach the audit
 * log (NEW2B-6109..6115), each as "entity -> verb" with its actor.
 */
class AuditLogActionsTest extends ApiTestCase
{
    private function last(string $modelType): ?AuditLog
    {
        return AuditLog::query()->where('model_type', $modelType)->latest('id')->first();
    }

    public function test_sending_a_message_is_logged_without_its_body(): void
    {
        ['headers' => $h, 'model' => $me] = $this->adminToken();
        $learner = User::factory()->create();

        $this->postJson(self::BASE.'/messages', [
            'subject' => 'Safety week', 'body' => 'Private body text',
            'groups' => [['type' => 'learner', 'ids' => [$learner->id]]],
        ], $h)->assertSuccessful();

        $row = $this->last(AdminMessage::class);
        $this->assertSame('sent', $row?->action);
        $this->assertSame($me->id, (int) $row->user_id);
        $this->assertStringContainsString('Safety week', $row->description);
        $this->assertStringNotContainsString('Private body text', $row->description);
    }

    public function test_creating_and_renaming_a_role_are_logged(): void
    {
        ['headers' => $h] = $this->adminToken();

        $id = $this->postJson(self::BASE.'/admin/roles', [
            'name_en' => 'Reviewers', 'name_ar' => 'المراجعون', 'permissions' => ['view-courses'],
        ], $h)->assertSuccessful()->json('result.id');
        $this->assertSame('created', $this->last(Role::class)?->action);

        // A rename alone, with no permission change, is still an edit.
        $this->putJson(self::BASE.'/admin/roles/'.$id, ['name_en' => 'Course reviewers'], $h)->assertSuccessful();
        $row = $this->last(Role::class);
        $this->assertSame('updated', $row?->action);
        $this->assertStringContainsString('name_en', $row->description);
    }

    public function test_replacing_the_certificate_template_is_logged(): void
    {
        Storage::fake('public');
        ['headers' => $h] = $this->adminToken();

        $this->post(self::BASE.'/admin/certificates/template', [
            'file' => UploadedFile::fake()->image('new-template.png', 800, 600),
        ], $h + ['Accept' => 'application/json'])->assertSuccessful();

        $row = $this->last(CertificateTemplate::class);
        $this->assertSame('replaced', $row?->action);
        $this->assertSame('new-template.png', $row->description);
    }

    public function test_granting_a_qualification_to_learners_is_logged(): void
    {
        ['headers' => $h] = $this->adminToken();
        $skill = QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات الأولية']]);
        $learners = User::factory()->count(2)->create();

        $this->postJson(self::BASE.'/admin/qualification-skills/'.$skill->id.'/learners', [
            'user_ids' => $learners->pluck('id')->all(),
        ], $h)->assertCreated();

        $row = $this->last(QualificationSkill::class);
        $this->assertSame('assigned', $row?->action);
        $this->assertStringContainsString('First Aid', $row->description);
        $this->assertStringContainsString('2', $row->description);
    }

    public function test_external_training_decisions_are_logged(): void
    {
        Storage::fake('private');
        ['headers' => $h] = $this->adminToken();
        $request = new ExternalTrainingRequest([
            'title' => 'Safety', 'provider' => 'Udemy', 'start_date' => '2026-05-01', 'end_date' => '2026-05-10',
            'hours' => 4, 'currency' => 'EGP', 'certificate_path' => 'external-training/x.pdf',
            'certificate_name' => 'x.pdf', 'certificate_mime' => 'application/pdf', 'certificate_size' => 10,
        ]);
        $request->user()->associate(User::factory()->create());
        $request->status = ExternalTrainingRequest::PENDING;
        $request->save();

        $this->postJson(self::BASE.'/admin/external-training/'.$request->id.'/reject', ['reason' => 'Certificate unreadable'], $h)
            ->assertSuccessful();

        $this->assertSame('rejected', $this->last(ExternalTrainingRequest::class)?->action);
    }
}
