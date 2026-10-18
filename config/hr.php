<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HR System Integration
    |--------------------------------------------------------------------------
    |
    | Settings for the external HR API used for employee authentication and
    | data sync. Read these through config() (never env() at runtime) so the
    | values keep working after `php artisan config:cache` / `optimize`.
    |
    */

    'base_url'       => env('HR_BASE_URL'),
    'verify_ssl'     => env('HR_VERIFY_SSL', true),
    'admin_email'    => env('HR_ADMIN_EMAIL'),
    'admin_password' => env('HR_ADMIN_PASSWORD'),

    // Where HR serves employee photos; the feed's `imageUrl` is a path under
    // it (NEW2B-6219). Public files, not a credential. https only.
    'files_base_url' => env('HR_FILES_BASE_URL', 'https://shantafactory.com/HR/AppMedia/'),

    // HR `employeeStatus` codes to the reports' Employment Status (D-112).
    // Only codes confirmed with HR are listed; any other code shows blank.
    // A resignation date on or before today always reads Terminated.
    'employee_statuses' => [
        0 => 'active',
    ],
];
