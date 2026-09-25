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
     * ── Why the list is now EMPTY (2026-09-25, I18N-03) ──────────────────────
     * It held 14 keys: platform identity, contact details, social links and the
     * "why us" blurb. An audit across all three repos found that nothing reads
     * any of them from this endpoint - the Angular Website never calls it, the
     * Dashboard edits settings through `GET/PUT admin/settings` (unfiltered by
     * this list), and the human confirmed the mobile app does not use
     * `/settings` at all.
     *
     * So the endpoint was publishing configuration that no client consumed -
     * which is precisely the shape of B-01: public, unauthenticated, and the
     * place a secret leaked from. An empty list exposes nothing.
     *
     * The route is kept rather than deleted: removing it is an API-contract
     * change, and an empty map is already zero exposure. Before adding a key
     * here, confirm a real client reads it from THIS endpoint - an unused public
     * key is attack surface with no benefit.
     *
     * @var list<string>
     */
    private const PUBLIC_KEYS = [];

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
