#!/bin/bash
#===================== implement backend  =======================
set -euo pipefail

echo "start deploy 2b website"

# ---------------------------------------------------------------------------
# Preserve the server's .env across the pull.
#
# .env used to be tracked (F-001), so `git pull` could overwrite the server's
# real configuration with whatever was committed. It is now gitignored and
# untracked, which means the opposite risk: a checkout must not remove it.
# Backing it up and restoring it makes the deploy safe under both states, and
# keeps a timestamped copy if a restore is ever needed.
# ---------------------------------------------------------------------------
ENV_BACKUP=".env.backup.$(date +%Y%m%d%H%M%S)"
if [ -f .env ]; then
    cp -p .env "$ENV_BACKUP"
    echo "backed up .env -> $ENV_BACKUP"
else
    echo "WARNING: no .env found before pull"
fi

if [ -f .git/index.lock ]; then
    echo "Lock file exists, removing it..."
    rm .git/index.lock
fi

git pull origin main

# Restore if the pull removed or replaced it.
if [ -f "$ENV_BACKUP" ]; then
    if ! cmp -s "$ENV_BACKUP" .env 2>/dev/null; then
        cp -p "$ENV_BACKUP" .env
        echo "restored .env from $ENV_BACKUP"
    fi
fi

# ---------------------------------------------------------------------------
# Production dependencies only.
#
# The previous `composer install` installed dev packages on the server, which
# put debugbar and ignition in production while `public/.htaccess` routes
# ^_debugbar to Laravel (B-25).
# ---------------------------------------------------------------------------
composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

php artisan migrate --force

# ---------------------------------------------------------------------------
# `php artisan db:seed --force` was REMOVED here. Do not put it back.
#
# DatabaseSeeder calls UserSeeder, and UserSeeder does `DB::table('users')
# ->delete()` followed by replaying a July 2026 snapshot of the users table.
# Running it on deploy therefore DESTROYED every user row created since that
# snapshot, together with everything keyed to those ids. Several other seeders
# truncate their tables the same way. See finding B-106.
#
# Seed data production genuinely needs (new settings keys, new permissions)
# belongs in an idempotent migration - as 2026_09_24_220000,
# 2026_09_25_090000 and 2026_09_25_120000 do for the admin permissions.
#
# A specific, known-idempotent seeder may be run explicitly and deliberately:
#   php artisan db:seed --class=SettingSeeder --force
# ---------------------------------------------------------------------------

php artisan optimize:clear

echo "Deployed Successfully............"
