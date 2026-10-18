<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HR every 24 hours (D-108): checked hourly, pulls when the last successful
// pull is a day old, so a missed or failed run is retried within the hour.
// Needs the server cron: * * * * * php artisan schedule:run
Schedule::command('sync:employees --if-due')->hourly()->withoutOverlapping(60);

// Roll cohort + course statuses forward every day right after
// midnight so the persisted columns track the calendar. Resources
// also derive these on the fly for live reads — this job exists so
// raw SQL consumers (reports, dashboards) stay in sync.
Schedule::command('cohorts:sync-statuses')->dailyAt('00:05');
// After the status sync: tell learners waiting for a course that a cohort opened (NEW2B-5780).
Schedule::command('cohorts:notify-interests')->dailyAt('00:15');
// Module uploads started but never completed (D-111).
Schedule::command('uploads:prune-chunks')->dailyAt('03:30');
// AI question generations older than 90 days (D-113; AiGeneration::prunable).
Schedule::command('model:prune', ['--model' => [\App\Models\AiGeneration::class]])->dailyAt('03:45');
