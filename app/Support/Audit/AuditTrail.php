<?php

namespace App\Support\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Instructor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes an audit row for an action that is not a plain model save, so the
 * model-event listener (AuditLogServiceProvider) never sees it: a message
 * sent, a role's permissions changed, a certificate template replaced,
 * qualifications granted through a pivot table (NEW2B-6109..6115).
 *
 * The row has the same shape as the listener's, so the Audit Log screen shows
 * it as "entity -> verb". The actor and their role are resolved the same way
 * too: a Dashboard account linked to an instructor, or holding the instructor
 * role, is logged as an instructor (NEW2B-6096).
 */
final class AuditTrail
{
    /**
     * @param class-string|string $modelType the record's class; its basename is the entity shown
     */
    public static function record(string $verb, string $modelType, int|string|null $modelId, string $description, ?Authenticatable $actor = null): void
    {
        $actor ??= self::currentActor();

        try {
            (new AuditLog())->forceFill([
                'user_type'   => $actor instanceof Admin ? 'admin' : ($actor === null ? 'system' : 'user'),
                'user_id'     => $actor?->getAuthIdentifier(),
                'user_name'   => $actor !== null ? (string) ($actor->name ?? '') : null,
                'actor_role'  => self::roleFor($actor),
                'action'      => $verb,
                'model_type'  => $modelType,
                'model_id'    => $modelId,
                'description' => mb_substr($description, 0, 1000),
                'ip_address'  => request()->ip(),
            ])->save();
        } catch (Throwable) {
            // Auditing never breaks the action itself.
        }
    }

    /** admin | instructor | learner | system */
    public static function roleFor(?Authenticatable $actor): string
    {
        if ($actor === null) {
            return 'system';
        }
        if ($actor instanceof Admin) {
            return $actor->instructor_id !== null || $actor->hasRole('instructor') ? 'instructor' : 'admin';
        }
        if ($actor instanceof Instructor) {
            return 'instructor';
        }

        return 'learner';
    }

    private static function currentActor(): ?Authenticatable
    {
        try {
            if ($user = Auth::user()) {
                return $user;
            }
            foreach (array_keys(config('auth.guards', [])) as $guard) {
                if ($user = Auth::guard($guard)->user()) {
                    return $user;
                }
            }
        } catch (Throwable) {
            // No session or guard: a system action.
        }

        return null;
    }
}
