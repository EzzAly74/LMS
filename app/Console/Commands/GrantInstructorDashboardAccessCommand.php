<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Instructor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * php artisan instructors:grant-dashboard-access {instructor*}
 *
 * Gives instructors that predate Dashboard accounts (D-075) a Dashboard
 * account of their own: the existing `instructor` role, linked to their
 * existing instructor record through `admins.instructor_id`, so they see
 * exactly their own courses, like any instructor account made from Users.
 *
 *   • The instructor record, its courses and its data are not changed.
 *   • No role is created: the command stops if the `instructor` role is missing.
 *   • The password is random and never shown, so the account cannot sign in
 *     until an admin sets a password from Users → Edit.
 *
 * Idempotent: an instructor that already has an account, or whose email is
 * already used by another account, is skipped and reported.
 */
class GrantInstructorDashboardAccessCommand extends Command
{
    protected $signature = 'instructors:grant-dashboard-access
        {instructor* : Instructor ids to give a Dashboard account}';

    protected $description = 'Create Dashboard accounts (existing Instructor role) linked to existing instructor records';

    public function handle(PermissionRegistrar $permissions): int
    {
        $role = Role::query()->where('name', 'instructor')->where('guard_name', 'admin')->first();
        if ($role === null) {
            $this->error('The instructor role does not exist; no account was created.');

            return self::FAILURE;
        }

        $failed = false;
        foreach (array_unique(array_map('intval', (array) $this->argument('instructor'))) as $id) {
            $instructor = Instructor::query()->find($id);
            $email = strtolower(trim((string) $instructor?->email));

            $skip = match (true) {
                $instructor === null => 'no such instructor',
                $email === '' => 'the instructor has no email',
                Admin::query()->where('instructor_id', $id)->exists() => 'already has a Dashboard account',
                Admin::query()->whereRaw('LOWER(email) = ?', [$email])->exists() => 'the email is used by another Dashboard account',
                default => null,
            };
            if ($skip !== null) {
                $this->warn("Instructor {$id}: skipped ({$skip}).");
                $failed = $failed || $instructor === null;
                continue;
            }

            $adminId = DB::transaction(function () use ($instructor, $email, $role) {
                $admin = new Admin();
                $admin->name = (string) ($instructor->getTranslation('name', 'en', false) ?: $instructor->getTranslation('name', 'ar', false));
                $admin->name_ar = (string) ($instructor->getTranslation('name', 'ar', false) ?: $admin->name);
                $admin->email = $email;
                $admin->image = $instructor->image;
                $admin->password = Hash::make(Str::random(64));
                $admin->status = 'active';
                $admin->save();
                $admin->forceFill(['instructor_id' => $instructor->id])->save();
                $admin->assignRole($role);

                return (int) $admin->id;
            });

            $this->info("Instructor {$id}: Dashboard account {$adminId} created ({$email}). Set its password from Users → Edit.");
        }

        $permissions->forgetCachedPermissions();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
