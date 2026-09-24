<?php

namespace App\Services;

use App\Repositories\Contracts\SettingRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    /**
     * Keys the unauthenticated `GET /api/v1/settings` endpoint may return.
     *
     * This is an allow-list, deliberately, so the endpoint is deny-by-default:
     * a new setting is private until it is named here. The previous behaviour
     * was the inverse — everything whose `type` was not `file` was published —
     * which leaked `mobile_shared_bearer_token`, the single shared secret
     * guarding `/api/v1/mobile/*`. Anyone could read it and then impersonate
     * any employee using only their machine code (B-01, Critical, confirmed
     * by exploit on 2026-09-24).
     *
     * Only public-brochure data belongs here: platform identity, contact
     * details, social links and the "why us" blurb. Operational tuning
     * (`mobile_*`, `min_passing_*`, `yearly_hours`) is not public even though
     * it is not secret — it is not the public site's business, and keeping the
     * list tight is what makes the next secret safe by default.
     *
     * @var list<string>
     */
    private const PUBLIC_KEYS = [
        // Platform identity
        'platform_name',
        'default_language',
        // Contact
        'email1',
        'email2',
        'phone1',
        'phone2',
        'whatsapp',
        // Social
        'facebook',
        'instagram',
        'linkedin',
        'snapchat',
        'twitter',
        'youtube',
        // About
        'why_us',
    ];

    public function __construct(
        private readonly SettingRepositoryInterface $settingRepository,
    ) {}

    /**
     * Return the publicly exposable settings as a plain key => value map.
     *
     * Consumed by the unauthenticated `GET /api/v1/settings`. Admin callers
     * that need the full set use `getAll()` behind `auth.user` + `role:Admin`.
     */
    public function getMap(): array
    {
        return $this->settingRepository->all()
            ->where('type', '!=', 'file')
            ->whereIn('key', self::PUBLIC_KEYS)
            ->pluck('value', 'key')
            ->all();
    }

    /** Return full settings collection for admin editing. */
    public function getAll(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->settingRepository->all();
    }

    public function updateMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->settingRepository->updateByKey((string) $key, $value);
        }

        Cache::forget('cms.settings.map');
        // Mobile thresholds (academy/attendance/rating/...) are cached
        // separately under `mobile.settings.map`. Without this, edits to a
        // `mobile_*` setting from the admin panel would lag up to 10 minutes.
        Cache::forget('mobile.settings.map');
        // Cross-module map (e.g. the attendance passcode mode + reset interval
        // live in Platform Config but are read by the mobile/passcode layer).
        // Flush it so a saved "Course Attendance" / "Passcode Reset" change
        // takes effect on the very next dashboard refresh.
        Cache::forget('settings.all.map');
    }
}
