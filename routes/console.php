<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Intervention expiry (2026-10-06). Runs only while a scheduler is running:
// `php artisan schedule:work` (development / XAMPP console) or the OS
// scheduler calling `php artisan schedule:run` every minute (hosting).
// withoutOverlapping: a slow run never starts a second copy.
Illuminate\Support\Facades\Schedule::command('dss:expire-interventions')->everyMinute()->withoutOverlapping(10);
