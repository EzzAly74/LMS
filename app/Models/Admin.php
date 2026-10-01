<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use App\Support\Permissions\AdminSections;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable,HasRoles;
    protected $guard_name = 'admin'; // optional if you're using multiple guards

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Holds a super-admin role (D-073). Such an admin passes every section
     * check, and only another super admin may edit, assign or remove it.
     */
    public function isSuperAdmin(): bool
    {
        return $this->getRoleNames()->contains(
            static fn (string $name) => AdminSections::isSuperAdminRole($name)
        );
    }

    /**
     * May perform `$action` in Dashboard section `$section` (D-073).
     */
    public function canInSection(string $section, string $action): bool
    {
        if (! AdminSections::supports($section, $action)) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->getAllPermissions()->contains('name', AdminSections::permission($section, $action));
    }

    /**
     * The matrix permissions this admin holds through their roles, by name.
     * Super admins hold all of them.
     *
     * @return list<string>
     */
    public function matrixPermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return AdminSections::permissionNames();
        }

        $all = AdminSections::permissionNames();

        return $this->getAllPermissions()
            ->pluck('name')
            ->filter(static fn (string $name) => in_array($name, $all, true))
            ->unique()
            ->values()
            ->all();
    }

    /** The instructor this Dashboard account signs in for (D-074), if any. */
    public function instructor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /** Deactivated and inactive accounts may not sign in or use a token. */
    public function isActive(): bool
    {
        return ! in_array(strtolower((string) ($this->status ?? 'active')), ['inactive', 'deactivated'], true);
    }

}
