<?php

namespace Tests\Feature\Console;

use App\Models\Admin;
use App\Models\Instructor;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * instructors:grant-dashboard-access gives an instructor record that has no
 * Dashboard account one: the existing instructor role, linked by
 * instructor_id, with a password nobody knows (D-075).
 */
class GrantInstructorDashboardAccessTest extends ApiTestCase
{
    private function instructor(string $email): Instructor
    {
        return Instructor::query()->create([
            'name' => ['en' => 'Karim Hassan', 'ar' => 'كريم حسن'], 'email' => $email, 'image' => 'Instructor/k.png',
        ]);
    }

    public function test_it_creates_a_linked_account_with_the_existing_instructor_role(): void
    {
        $role = Role::findOrCreate('instructor', 'admin');
        $roles = Role::query()->count();
        $instructor = $this->instructor('K@Hassan.test');

        $this->artisan('instructors:grant-dashboard-access', ['instructor' => [$instructor->id]])->assertSuccessful();

        $admin = Admin::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $this->assertSame('k@hassan.test', $admin->email);
        $this->assertSame('Karim Hassan', $admin->name);
        $this->assertSame('كريم حسن', $admin->name_ar);
        $this->assertSame('Instructor/k.png', $admin->image);
        $this->assertSame('active', $admin->status);
        $this->assertSame([$role->id], $admin->roles()->pluck('id')->all());
        $this->assertSame($roles, Role::query()->count());
        $this->assertSame(1, Instructor::query()->where('email', 'K@Hassan.test')->count());
        $this->assertSame('Karim Hassan', $instructor->fresh()->getTranslation('name', 'en'));

        // Nobody knows the password until an admin sets one.
        $this->postJson(self::BASE.'/auth/admin/login', ['email' => 'k@hassan.test', 'password' => ''])->assertStatus(422);
    }

    public function test_running_it_again_or_on_a_taken_email_creates_nothing(): void
    {
        Role::findOrCreate('instructor', 'admin');
        $linked = $this->instructor('once@academy.test');
        $this->artisan('instructors:grant-dashboard-access', ['instructor' => [$linked->id]])->assertSuccessful();
        $taken = $this->instructor('taken@academy.test');
        Admin::factory()->create(['email' => 'taken@academy.test']);
        $before = Admin::query()->count();

        $this->artisan('instructors:grant-dashboard-access', ['instructor' => [$linked->id, $taken->id]])->assertSuccessful();

        $this->assertSame($before, Admin::query()->count());
        $this->assertNull(Admin::query()->where('email', 'taken@academy.test')->value('instructor_id'));
    }

    public function test_it_creates_no_role_when_the_instructor_role_is_missing(): void
    {
        Role::query()->where('name', 'instructor')->delete();
        $instructor = $this->instructor('none@academy.test');

        $this->artisan('instructors:grant-dashboard-access', ['instructor' => [$instructor->id]])->assertFailed();

        $this->assertFalse(Role::query()->where('name', 'instructor')->exists());
        $this->assertFalse(Admin::query()->where('instructor_id', $instructor->id)->exists());
    }
}
