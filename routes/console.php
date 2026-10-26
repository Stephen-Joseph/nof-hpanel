<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ssl:renew-expiring')->daily();
Schedule::command('domains:verify-pending')->everyFifteenMinutes();
Schedule::command('usage:collect')->everyFifteenMinutes();
Schedule::command('bandwidth:reset')->monthlyOn(1, '00:00');
Schedule::command('backups:run-scheduled')->everyMinute();
Schedule::command('api:prune-logs')->daily();
