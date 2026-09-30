<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\UserCertificate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/** GET admin/certificates - the Dashboard's Issued Certificates list. */
class AdminCertificateListTest extends ApiTestCase
{
    private const URL = self::BASE.'/admin/certificates';

    public function test_the_page_size_is_bounded(): void
    {
        UserCertificate::factory()->count(3)->create();
        ['headers' => $h] = $this->adminToken();

        $this->getJson(self::URL.'?per_page=2', $h)->assertOk()->assertJsonCount(2, 'result.data')->assertJsonPath('result.per_page', 2);
        $this->getJson(self::URL.'?per_page=100000', $h)->assertOk()->assertJsonPath('result.per_page', 200);
        $this->getJson(self::URL.'?per_page=0', $h)->assertOk()->assertJsonPath('result.per_page', 1);
    }

    public function test_only_certificate_viewers_see_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $admin = Admin::factory()->create();
        $role = Role::findOrCreate('no-certs-'.uniqid(), 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-courses', 'admin'));
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson(self::URL, $this->adminToken($admin)['headers'])->assertForbidden();

        $this->getJson(self::URL, $this->userToken()['headers'])->assertForbidden();
    }
}
