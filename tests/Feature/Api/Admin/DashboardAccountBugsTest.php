<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Instructor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regressions for the Users screen bugs of 2026-10 (NEW2B-6150..6174).
 */
class DashboardAccountBugsTest extends ApiTestCase
{
    private function superAdmin(): array
    {
        $admin = Admin::factory()->create();
        $admin->assignRole(Role::findOrCreate('superAdmin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->adminToken($admin);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name_en' => 'Sara Ali', 'name_ar' => 'سارة علي', 'email' => uniqid().'@academy.test',
            'password' => 'Strong-Pass-2026', 'password_confirmation' => 'Strong-Pass-2026', 'role' => 'admin',
        ], $overrides);
    }

    /** NEW2B-6150: one account, the chosen role, only that role's counter moves. */
    public function test_adding_an_instructor_adds_one_account_with_the_instructor_role(): void
    {
        ['headers' => $h] = $this->superAdmin();
        Role::findOrCreate('instructor', 'admin');
        $before = $this->getJson(self::BASE.'/admin/users/summary', $h)->assertOk()->json('result');

        $row = $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'instructor']), $h)
            ->assertCreated()->json('result');

        $after = $this->getJson(self::BASE.'/admin/users/summary', $h)->json('result');
        $this->assertSame('instructor', $row['role_key']);
        $this->assertSame($before['total_users'] + 1, $after['total_users']);
        $this->assertSame($before['instructors'] + 1, $after['instructors']);
        $this->assertSame($before['admins'], $after['admins']);
        $this->assertSame(1, Admin::query()->where('email', $row['email'])->count());
    }

    /** NEW2B-6157 / NEW2B-6158: the exact role chosen is the role saved, never a near name. */
    public function test_the_chosen_role_is_saved_exactly(): void
    {
        ['headers' => $h] = $this->superAdmin();
        Role::findOrCreate('reports-viewer', 'admin');
        Role::findOrCreate('test', 'admin');
        Role::findOrCreate('tester', 'admin');

        $viewer = $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'reports-viewer']), $h)
            ->assertCreated()->json('result');
        $test = $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'test']), $h)
            ->assertCreated()->json('result');

        $this->assertSame('reports-viewer', $viewer['role_key']);
        $this->assertSame(['reports-viewer'], Admin::query()->find($viewer['id'])->getRoleNames()->all());
        $this->assertSame('test', $test['role_key']);
        $this->assertSame(['test'], Admin::query()->find($test['id'])->getRoleNames()->all());
    }

    /** NEW2B-6152: the Admin filter returns admins only. */
    public function test_the_admin_filter_returns_only_admins(): void
    {
        ['headers' => $h] = $this->superAdmin();
        Role::findOrCreate('instructor', 'admin');
        Role::findOrCreate('reports-viewer', 'admin');
        foreach (['admin', 'instructor', 'reports-viewer'] as $role) {
            $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => $role]), $h)->assertCreated();
        }

        $rows = $this->getJson(self::BASE.'/admin/users?role=admin&per_page=100', $h)->assertOk()->json('result');

        $this->assertNotEmpty($rows);
        $this->assertSame(['admin'], array_values(array_unique(array_column($rows, 'role_key'))));
    }

    /** NEW2B-6151: a deactivated account cannot sign in. */
    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        ['headers' => $h] = $this->superAdmin();
        $row = $this->postJson(self::BASE.'/admin/users', $this->payload(), $h)->assertCreated()->json('result');
        $this->deleteJson(self::BASE."/admin/users/admin/{$row['id']}", [], $h)->assertOk();

        $this->postJson(self::BASE.'/auth/admin/login', ['email' => $row['email'], 'password' => 'Strong-Pass-2026'])
            ->assertForbidden();

        $this->patchJson(self::BASE."/admin/users/admin/{$row['id']}/reactivate", [], $h)->assertOk();
        $this->postJson(self::BASE.'/auth/admin/login', ['email' => $row['email'], 'password' => 'Strong-Pass-2026'])
            ->assertOk();
    }

    /** NEW2B-6161: at least two names of three letters or more, at most 100 characters, both languages. */
    public function test_full_names_follow_the_name_rules(): void
    {
        ['headers' => $h] = $this->superAdmin();

        foreach (['Sara', 'Sa Ali', str_repeat('Abcdefghij ', 10)] as $bad) {
            $this->postJson(self::BASE.'/admin/users', $this->payload(['name_en' => $bad]), $h)
                ->assertStatus(422)->assertJsonValidationErrors('name_en');
        }
        $this->postJson(self::BASE.'/admin/users', $this->payload(['name_ar' => 'سارة']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('name_ar');

        $row = $this->postJson(self::BASE.'/admin/users', $this->payload(['name_en' => '  Sara   Mohamed Ali ', 'name_ar' => 'علي محمد']), $h)
            ->assertCreated()->json('result');
        $this->assertSame('Sara Mohamed Ali', $row['name_en']);

        $this->putJson(self::BASE."/admin/users/admin/{$row['id']}", ['name_en' => 'Sara'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('name_en');
    }

    /** NEW2B-6162: a taken email says what to do. */
    public function test_a_taken_email_says_what_to_do(): void
    {
        ['headers' => $h] = $this->superAdmin();
        $existing = Admin::factory()->create(['email' => 'taken@academy.test']);

        $this->postJson(self::BASE.'/admin/users', $this->payload(['email' => 'Taken@academy.test']), $h)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => __('validation.account_email_taken')]);
        $this->assertSame(1, Admin::query()->where('email', $existing->email)->count());
    }

    /** NEW2B-6165: a GIF photo is accepted and shown. */
    public function test_a_gif_photo_is_accepted(): void
    {
        Storage::fake('public');
        ['headers' => $h] = $this->superAdmin();

        $row = $this->post(self::BASE.'/admin/users', $this->payload([
            'image' => UploadedFile::fake()->image('me.gif', 120, 120),
        ]), $h + ['Accept' => 'application/json'])->assertCreated()->json('result');

        $this->assertNotNull($row['image']);
    }

    /** NEW2B-6166 / NEW2B-6167: SVG and other types are refused, and so is a too-large photo, with clear messages. */
    public function test_wrong_type_and_too_large_photos_get_clear_messages(): void
    {
        Storage::fake('public');
        ['headers' => $h] = $this->superAdmin();
        $json = $h + ['Accept' => 'application/json'];

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post(self::BASE.'/admin/users', $this->payload(['image' => $svg]), $json)
            ->assertStatus(422)->assertJsonValidationErrors(['image' => __('validation.account_image.mimes')]);

        $pdf = UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf');
        $this->post(self::BASE.'/admin/users', $this->payload(['image' => $pdf]), $json)
            ->assertStatus(422)->assertJsonValidationErrors(['image' => __('validation.account_image.mimes')]);

        $big = UploadedFile::fake()->image('big.png', 100, 100)->size(4000);
        $this->post(self::BASE.'/admin/users', $this->payload(['image' => $big]), $json)
            ->assertStatus(422)->assertJsonValidationErrors(['image' => __('validation.account_image.max')]);
    }

    /** NEW2B-6160: removing the photo and saving removes it, from the instructor record too. */
    public function test_removing_the_photo_removes_it(): void
    {
        Storage::fake('public');
        ['headers' => $h] = $this->superAdmin();
        Role::findOrCreate('instructor', 'admin');
        $row = $this->post(self::BASE.'/admin/users', $this->payload([
            'role' => 'instructor', 'image' => UploadedFile::fake()->image('me.png', 120, 120),
        ]), $h + ['Accept' => 'application/json'])->assertCreated()->json('result');
        $path = Admin::query()->find($row['id'])->image;
        $this->assertNotNull($path);
        $this->assertSame($path, Instructor::query()->find($row['instructor_id'])->image);

        $updated = $this->putJson(self::BASE."/admin/users/admin/{$row['id']}", ['remove_image' => true], $h)
            ->assertOk()->json('result');

        $this->assertNull($updated['image']);
        $this->assertNull(Admin::query()->find($row['id'])->image);
        $this->assertNull(Instructor::query()->find($row['instructor_id'])->image);
        Storage::disk('public')->assertMissing($path);
    }

    /** NEW2B-6174: a renamed account shows the new name, in the list and to the account itself. */
    public function test_a_rename_reaches_the_account_itself(): void
    {
        ['headers' => $h] = $this->superAdmin();
        $row = $this->postJson(self::BASE.'/admin/users', $this->payload(), $h)->assertCreated()->json('result');

        $this->putJson(self::BASE."/admin/users/admin/{$row['id']}", ['name_en' => 'Sara Hassan', 'name_ar' => 'سارة حسن'], $h)->assertOk();

        $token = $this->postJson(self::BASE.'/auth/admin/login', ['email' => $row['email'], 'password' => 'Strong-Pass-2026'])
            ->assertOk()->json('result.token');
        $me = $this->getJson(self::BASE.'/auth/admin/me', ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');
        $this->assertSame('Sara Hassan', $me['name']);
    }
}
