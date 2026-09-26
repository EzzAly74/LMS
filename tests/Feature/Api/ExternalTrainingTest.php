<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\ExternalTrainingRequest;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Notifications\ExternalTrainingDecidedNotification;
use App\Notifications\ExternalTrainingSubmittedNotification;
use App\Services\UserDashboardService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * External Training (D-035, D-057): learner submit / edit / withdraw, admin
 * list / approve / reject, super-admin reopen, certificates on the private
 * disk behind authorized routes.
 */
class ExternalTrainingTest extends ApiTestCase
{
    private const LEARNER = self::BASE.'/learner/external-training';
    private const ADMIN = self::BASE.'/admin/external-training';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
    }

    private function fields(array $over = []): array
    {
        return array_merge([
            'title'      => 'Advanced Excel for Finance',
            'provider'   => 'Coursera',
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date'   => now()->subMonth()->toDateString(),
            'hours'      => '12.5',
            'cost'       => '1500',
        ], $over);
    }

    private function pdf(string $name = 'certificate.pdf', int $kb = 50): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function submit(array $headers, array $over = [], ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::LEARNER, $this->fields($over) + ['certificate' => $file ?? $this->pdf()], $headers + ['Accept' => 'application/json']);
    }

    /** A request row created directly, for the admin side. */
    private function request(User $user, string $status = ExternalTrainingRequest::PENDING, array $over = []): ExternalTrainingRequest
    {
        Storage::disk('private')->put("external-training/{$user->id}-".uniqid().'.pdf', '%PDF-1.4 test');
        $path = collect(Storage::disk('private')->files('external-training'))->last();
        $r = new ExternalTrainingRequest(array_merge([
            'title' => 'Workplace Safety Essentials', 'provider' => 'Udemy',
            'start_date' => '2026-05-01', 'end_date' => '2026-05-10', 'hours' => 20, 'cost' => null, 'currency' => 'EGP',
            'certificate_path' => $path, 'certificate_name' => 'cert.pdf', 'certificate_mime' => 'application/pdf', 'certificate_size' => 13,
        ], $over));
        $r->user()->associate($user);
        $r->status = $status;
        $r->save();

        return $r;
    }

    private function reviewer(array $permissions = ['view-external-training'], ?string $role = null): array
    {
        $admin = Admin::factory()->create();
        $r = Role::findOrCreate($role ?? 'et-'.uniqid(), 'admin');
        foreach ($permissions as $p) {
            $r->givePermissionTo(Permission::findOrCreate($p, 'admin'));
        }
        $admin->assignRole($r);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->adminToken($admin);
    }

    // ────────────────────────────────────────────────────────────── learner

    public function test_a_learner_submits_a_request_and_the_reviewers_are_told(): void
    {
        ['model' => $reviewer] = $this->reviewer();
        ['model' => $learner, 'headers' => $h] = $this->userToken();

        $res = $this->submit($h)->assertCreated()->json('result');

        $this->assertSame('pending', $res['status']);
        $this->assertEquals(12.5, $res['hours']);
        $this->assertEquals(1500, $res['cost']);
        $this->assertSame('EGP', $res['currency']);
        $this->assertSame('certificate.pdf', $res['certificate']['name']);
        $this->assertArrayNotHasKey('certificate_path', $res);
        $row = ExternalTrainingRequest::query()->findOrFail($res['id']);
        $this->assertSame($learner->id, $row->user_id);
        Storage::disk('private')->assertExists($row->certificate_path);
        $this->assertStringNotContainsString('certificate', basename($row->certificate_path), 'The stored name is made by the server.');
        Notification::assertSentTo($reviewer, ExternalTrainingSubmittedNotification::class);
    }

    public function test_invalid_input_is_refused_and_writes_nothing(): void
    {
        ['headers' => $h] = $this->userToken();

        $this->submit($h, ['end_date' => now()->subMonths(3)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');
        $this->submit($h, ['end_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('end_date', 'errors');
        $this->submit($h, ['hours' => '1.3'])->assertStatus(422)->assertJsonValidationErrors('hours', 'errors');
        $this->submit($h, ['hours' => '0'])->assertStatus(422)->assertJsonValidationErrors('hours', 'errors');
        $this->submit($h, ['cost' => '-5'])->assertStatus(422)->assertJsonValidationErrors('cost', 'errors');
        $this->submit($h, ['title' => str_repeat('a', 192)])->assertStatus(422)->assertJsonValidationErrors('title', 'errors');
        $this->submit($h, [], $this->pdf('big.pdf', 10241))->assertStatus(422)->assertJsonValidationErrors('certificate', 'errors');
        $this->post(self::LEARNER, $this->fields(), $h + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('certificate', 'errors');

        $this->assertSame(0, ExternalTrainingRequest::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_certificate_is_judged_by_its_content_not_its_name(): void
    {
        ['headers' => $h] = $this->userToken();
        // A real file, not UploadedFile::fake(): a fake reports the MIME type of its extension.
        $path = tempnam(sys_get_temp_dir(), 'et');
        file_put_contents($path, '<?php echo shell_exec($_GET["c"]); ?>');

        $this->submit($h, [], new UploadedFile($path, 'certificate.pdf', null, null, true))
            ->assertStatus(422)->assertJsonValidationErrors('certificate', 'errors');
        $this->assertSame(0, ExternalTrainingRequest::query()->count());
    }

    public function test_a_learner_sees_only_their_own_requests(): void
    {
        ['model' => $me, 'headers' => $h] = $this->userToken();
        $mine   = $this->request($me);
        $this->request($me, ExternalTrainingRequest::WITHDRAWN);
        $theirs = $this->request(User::factory()->create());

        $ids = collect($this->getJson(self::LEARNER, $h)->assertOk()->json('result'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);

        // Another learner's request does not exist for me: 404 on every route.
        $this->getJson(self::LEARNER.'/'.$theirs->id, $h)->assertNotFound();
        $this->post(self::LEARNER.'/'.$theirs->id, $this->fields(), $h + ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson(self::LEARNER.'/'.$theirs->id, [], $h)->assertNotFound();
        $this->get(self::LEARNER.'/'.$theirs->id.'/certificate', $h)->assertNotFound();
        $this->assertSame(ExternalTrainingRequest::PENDING, $theirs->fresh()->status);
    }

    public function test_a_pending_request_can_be_edited_and_its_certificate_replaced(): void
    {
        ['model' => $me, 'headers' => $h] = $this->userToken();
        $r   = $this->request($me);
        $old = $r->certificate_path;

        $this->post(self::LEARNER.'/'.$r->id, $this->fields(['title' => 'Excel II']) + ['certificate' => $this->pdf('new.pdf')], $h + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('result.title', 'Excel II')->assertJsonPath('result.certificate.name', 'new.pdf');

        Storage::disk('private')->assertMissing($old);
        Storage::disk('private')->assertExists($r->fresh()->certificate_path);

        // Without a new file the certificate stays.
        $this->post(self::LEARNER.'/'.$r->id, $this->fields(['title' => 'Excel III']), $h + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('result.certificate.name', 'new.pdf');
    }

    public function test_a_decided_request_cannot_be_edited_or_withdrawn(): void
    {
        ['model' => $me, 'headers' => $h] = $this->userToken();
        $r = $this->request($me, ExternalTrainingRequest::APPROVED);

        $this->post(self::LEARNER.'/'.$r->id, $this->fields(['title' => 'Changed']), $h + ['Accept' => 'application/json'])->assertStatus(422);
        $this->deleteJson(self::LEARNER.'/'.$r->id, [], $h)->assertStatus(422);
        $this->assertSame('Workplace Safety Essentials', $r->fresh()->title);
        $this->assertSame(ExternalTrainingRequest::APPROVED, $r->fresh()->status);
    }

    public function test_withdrawing_removes_the_request_and_its_certificate(): void
    {
        ['model' => $me, 'headers' => $h] = $this->userToken();
        $r = $this->request($me);

        $this->deleteJson(self::LEARNER.'/'.$r->id, [], $h)->assertOk();

        $this->assertSame(ExternalTrainingRequest::WITHDRAWN, $r->fresh()->status);
        Storage::disk('private')->assertMissing($r->certificate_path);
        $this->getJson(self::LEARNER.'/'.$r->id, $h)->assertNotFound();
        ['headers' => $a] = $this->reviewer();
        $this->getJson(self::ADMIN.'/'.$r->id, $a)->assertNotFound();
    }

    public function test_the_learner_downloads_their_own_certificate(): void
    {
        ['model' => $me, 'headers' => $h] = $this->userToken();
        $r = $this->request($me);

        $res = $this->get(self::LEARNER.'/'.$r->id.'/certificate', $h)->assertOk();
        $this->assertStringContainsString('cert.pdf', (string) $res->headers->get('content-disposition'));
    }

    // ──────────────────────────────────────────────────────────────── admin

    public function test_the_admin_list_has_tiles_filters_and_puts_pending_first(): void
    {
        $layla = User::factory()->create(['name' => 'Layla Hassan']);
        $omar  = User::factory()->create(['name' => 'Omar Al-Farsi']);
        $approved = $this->request($layla, ExternalTrainingRequest::APPROVED);
        $pending  = $this->request($omar);
        $this->request($omar, ExternalTrainingRequest::REJECTED, ['provider' => 'LinkedIn Learning']);
        $this->request($omar, ExternalTrainingRequest::WITHDRAWN);
        ['headers' => $h] = $this->reviewer();

        $res = $this->getJson(self::ADMIN, $h)->assertOk();
        $this->assertSame(['pending' => 1, 'this_year' => 3, 'rejected_this_year' => 1, 'year' => (int) now()->year], $res->json('meta.stats'));
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertSame($pending->id, $res->json('result.0.id'));
        $this->assertSame('Omar Al-Farsi', $res->json('result.0.learner.name'));

        $this->assertSame([$approved->id], collect($this->getJson(self::ADMIN.'?statuses[]=approved', $h)->json('result'))->pluck('id')->all());
        $this->assertCount(1, $this->getJson(self::ADMIN.'?search=linkedin', $h)->json('result'));
        $this->assertCount(1, $this->getJson(self::ADMIN.'?search=layla', $h)->json('result'));
        $this->getJson(self::ADMIN.'?statuses[]=withdrawn', $h)->assertStatus(422);
        $this->getJson(self::ADMIN.'?per_page=1000', $h)->assertStatus(422);
    }

    public function test_approving_without_a_qualification_credits_the_learner(): void
    {
        $learner = User::factory()->create();
        $r = $this->request($learner, ExternalTrainingRequest::PENDING, ['end_date' => now()->toDateString(), 'start_date' => now()->toDateString()]);
        ['model' => $admin, 'headers' => $h] = $this->reviewer();

        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', [], $h)->assertOk()
            ->assertJsonPath('result.status', 'approved')
            ->assertJsonPath('result.decided_by.id', $admin->id)
            ->assertJsonPath('result.qualification', null);

        $this->assertSame(0, DB::table('user_qualification_skill')->count());
        Notification::assertSentTo($learner, ExternalTrainingDecidedNotification::class,
            fn ($n) => $n->toDatabase($learner)['type'] === 'external_training_approved');
        // The hours count as this year's training hours, and it is a completed entry.
        $this->assertSame(20.0, app(UserDashboardService::class)->getStats($learner)['year_hours']);
        ['headers' => $lh] = $this->userToken($learner);
        $entry = collect($this->getJson(self::BASE.'/learner/profile/completed', $lh)->assertOk()->json('result'))->firstWhere('kind', 'external');
        $this->assertSame($r->id, $entry['external_id']);
        $this->assertEquals(20, $entry['hours']);
    }

    public function test_approving_with_a_qualification_grants_it_once(): void
    {
        $learner = User::factory()->create();
        $q = QualificationSkill::query()->create(['name' => ['en' => 'Financial Analysis', 'ar' => 'التحليل المالي']]);
        $r = $this->request($learner);
        ['headers' => $h] = $this->reviewer();

        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', ['qualification_skill_id' => $q->id], $h)->assertOk()
            ->assertJsonPath('result.qualification.name', 'Financial Analysis');

        $this->assertTrue($r->fresh()->grant_created);
        $this->assertDatabaseHas('user_qualification_skill', ['user_id' => $learner->id, 'qualification_skill_id' => $q->id]);

        // Already held: no second grant, and the request did not create one.
        $second = $this->request($learner);
        $this->postJson(self::ADMIN.'/'.$second->id.'/approve', ['qualification_skill_id' => $q->id], $h)->assertOk();
        $this->assertFalse($second->fresh()->grant_created);
        $this->assertSame(1, DB::table('user_qualification_skill')->count());

        $this->postJson(self::ADMIN.'/'.$this->request($learner)->id.'/approve', ['qualification_skill_id' => 999999], $h)->assertStatus(422);
    }

    public function test_a_decision_is_made_once(): void
    {
        $r = $this->request(User::factory()->create());
        ['headers' => $h] = $this->reviewer();

        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', [], $h)->assertOk();
        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', [], $h)->assertStatus(422);
        $this->postJson(self::ADMIN.'/'.$r->id.'/reject', ['reason' => 'Too late'], $h)->assertStatus(422);
        $this->assertSame(ExternalTrainingRequest::APPROVED, $r->fresh()->status);
    }

    public function test_rejecting_needs_a_reason_which_the_learner_is_sent(): void
    {
        $learner = User::factory()->create();
        $r = $this->request($learner);
        ['headers' => $h] = $this->reviewer();

        $this->postJson(self::ADMIN.'/'.$r->id.'/reject', ['reason' => '   '], $h)->assertStatus(422)->assertJsonValidationErrors('reason', 'errors');
        $this->postJson(self::ADMIN.'/'.$r->id.'/reject', ['reason' => str_repeat('x', 1001)], $h)->assertStatus(422);
        $this->assertSame(ExternalTrainingRequest::PENDING, $r->fresh()->status);

        $this->postJson(self::ADMIN.'/'.$r->id.'/reject', ['reason' => 'The certificate is not legible.'], $h)->assertOk()
            ->assertJsonPath('result.status', 'rejected')->assertJsonPath('result.rejection_reason', 'The certificate is not legible.');
        Notification::assertSentTo($learner, ExternalTrainingDecidedNotification::class,
            fn ($n) => str_contains($n->toDatabase($learner)['body_en'], 'The certificate is not legible.'));
    }

    public function test_only_a_super_admin_reopens_and_only_the_grant_the_request_made_goes(): void
    {
        $learner = User::factory()->create();
        $q = QualificationSkill::query()->create(['name' => ['en' => 'Safety', 'ar' => 'سلامة']]);
        $r = $this->request($learner);
        ['headers' => $h] = $this->reviewer();
        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', ['qualification_skill_id' => $q->id], $h)->assertOk();

        $this->postJson(self::ADMIN.'/'.$r->id.'/reopen', [], $h)->assertForbidden();
        $this->assertSame(ExternalTrainingRequest::APPROVED, $r->fresh()->status);

        ['headers' => $super] = $this->reviewer(['view-external-training'], 'superAdmin');
        $this->postJson(self::ADMIN.'/'.$r->id.'/reopen', [], $super)->assertOk()->assertJsonPath('result.status', 'pending');
        $this->assertSame(0, DB::table('user_qualification_skill')->count());
        $this->postJson(self::ADMIN.'/'.$r->id.'/reopen', [], $super)->assertStatus(422);

        // A grant the request did not make survives a reopen.
        $held = QualificationSkill::query()->create(['name' => ['en' => 'Held', 'ar' => 'محمول']]);
        DB::table('user_qualification_skill')->insert(['user_id' => $learner->id, 'qualification_skill_id' => $held->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', ['qualification_skill_id' => $held->id], $h)->assertOk();
        $this->postJson(self::ADMIN.'/'.$r->id.'/reopen', [], $super)->assertOk();
        $this->assertDatabaseHas('user_qualification_skill', ['user_id' => $learner->id, 'qualification_skill_id' => $held->id]);
    }

    public function test_the_admin_downloads_the_certificate(): void
    {
        $r = $this->request(User::factory()->create());
        ['headers' => $h] = $this->reviewer();

        $this->get(self::ADMIN.'/'.$r->id.'/certificate', $h)->assertOk();
        $this->getJson(self::ADMIN.'/999999', $h)->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────── access

    public function test_access_is_refused_to_everyone_else(): void
    {
        $r = $this->request(User::factory()->create());
        ['headers' => $blind] = $this->reviewer(['view-dashboard']);
        ['headers' => $learner] = $this->userToken();
        ['headers' => $reviewer] = $this->reviewer();

        foreach ([$blind, $learner] as $h) {
            $this->getJson(self::ADMIN, $h)->assertForbidden();
            $this->getJson(self::ADMIN.'/'.$r->id, $h)->assertForbidden();
            $this->postJson(self::ADMIN.'/'.$r->id.'/approve', [], $h)->assertForbidden();
            $this->get(self::ADMIN.'/'.$r->id.'/certificate', $h + ['Accept' => 'application/json'])->assertForbidden();
        }
        // Admins are not learners.
        $this->getJson(self::LEARNER, $reviewer)->assertForbidden();
        $this->getJson(self::ADMIN)->assertUnauthorized();
        $this->getJson(self::LEARNER)->assertUnauthorized();
        $this->assertSame(ExternalTrainingRequest::PENDING, $r->fresh()->status);
    }
}
