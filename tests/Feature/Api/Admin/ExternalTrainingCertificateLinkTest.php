<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\ExternalTrainingRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * The Dashboard's certificate download (D-071): an authorized reviewer gets a
 * short signed link, and the link alone serves the file - so a download
 * manager that takes over the download can fetch it without the token.
 */
class ExternalTrainingCertificateLinkTest extends ApiTestCase
{
    private const ADMIN = self::BASE.'/admin/external-training';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function request(string $status = ExternalTrainingRequest::PENDING): ExternalTrainingRequest
    {
        $path = 'external-training/'.uniqid().'.pdf';
        Storage::disk('private')->put($path, '%PDF-1.4 link test');
        $r = new ExternalTrainingRequest([
            'title' => 'Workplace Safety Essentials', 'provider' => 'Udemy',
            'start_date' => '2026-05-01', 'end_date' => '2026-05-10', 'hours' => 20, 'cost' => null, 'currency' => 'EGP',
            'certificate_path' => $path, 'certificate_name' => 'invoice 2026.pdf', 'certificate_mime' => 'application/pdf', 'certificate_size' => 18,
        ]);
        $r->user()->associate(User::factory()->create());
        $r->status = $status;
        $r->save();

        return $r;
    }

    /** @return array<string, string> */
    private function reviewer(array $permissions = ['view-external-training']): array
    {
        $admin = Admin::factory()->create();
        $role = Role::findOrCreate('et-link-'.uniqid(), 'admin');
        foreach ($permissions as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'admin'));
        }
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->adminToken($admin)['headers'];
    }

    private function link(ExternalTrainingRequest $r, array $headers): string
    {
        return $this->getJson(self::ADMIN.'/'.$r->id.'/certificate-link', $headers)
            ->assertOk()->assertJsonStructure(['result' => ['url', 'expires_at']])->json('result.url');
    }

    public function test_a_reviewer_gets_a_link_that_downloads_the_file_without_a_token(): void
    {
        $r = $this->request();
        $url = $this->link($r, $this->reviewer());

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString($r->certificate_path, $url, 'The storage path never leaves the server.');

        // No Authorization header: the signature is the authorization.
        $res = $this->get($url);
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertSame('%PDF-1.4 link test', $res->streamedContent());
    }

    public function test_only_reviewers_get_a_link(): void
    {
        $r = $this->request();

        $this->getJson(self::ADMIN.'/'.$r->id.'/certificate-link')->assertUnauthorized();
        $this->getJson(self::ADMIN.'/'.$r->id.'/certificate-link', $this->reviewer(['view-courses']))->assertForbidden();
        $this->getJson(self::ADMIN.'/'.$r->id.'/certificate-link', $this->userToken($r->user)['headers'])->assertForbidden();
    }

    public function test_a_withdrawn_or_missing_request_has_no_link(): void
    {
        $h = $this->reviewer();
        $this->getJson(self::ADMIN.'/'.$this->request(ExternalTrainingRequest::WITHDRAWN)->id.'/certificate-link', $h)->assertNotFound();
        $this->getJson(self::ADMIN.'/999999/certificate-link', $h)->assertNotFound();
    }

    public function test_a_link_cannot_be_forged_moved_to_another_request_or_used_after_it_expires(): void
    {
        $r = $this->request();
        $other = $this->request();
        $url = $this->link($r, $this->reviewer());

        $this->get(self::BASE.'/external-training-files/'.$r->id)->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        $this->get(str_replace('/external-training-files/'.$r->id, '/external-training-files/'.$other->id, $url))->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_a_link_stops_working_once_the_request_is_withdrawn(): void
    {
        $r = $this->request();
        $url = $this->link($r, $this->reviewer());

        $r->status = ExternalTrainingRequest::WITHDRAWN;
        $r->save();

        $this->get($url)->assertNotFound();
    }
}
