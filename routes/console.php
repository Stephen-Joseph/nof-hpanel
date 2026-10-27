<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pma:prune-sessions', function () {
    $this->info('Pruning expired phpMyAdmin SSO sessions...');
    dispatch_sync(new \App\Jobs\PrunePmaSsoSessionsJob);
    $this->info('Expired phpMyAdmin SSO sessions pruned.');
})->purpose('Drop expired temporary MariaDB SSO users');

Artisan::command('domains:regenerate-vhost {domain=copyplusdtp.in} {profile=php-generic}', function () {
    $domainName = (string) $this->argument('domain');
    $profile = (string) $this->argument('profile');

    /** @var \App\Models\Domain|null $domain */
    $domain = \App\Models\Domain::with(['account.server', 'account.package', 'certificate', 'redirects', 'protectedDirectories'])
        ->where('domain', $domainName)
        ->first();

    if (! $domain) {
        $this->warn("Domain '{$domainName}' was not found in the database.");

        return 1;
    }

    $appProfile = \App\Enums\AppProfile::tryFrom($profile) ?? \App\Enums\AppProfile::PHP_GENERIC;
    $domain->update(['app_profile' => $appProfile]);

    dispatch_sync(new \App\Jobs\WriteVhostJob($domain));
    $this->info("Domain '{$domainName}' updated to '{$appProfile->value}' and virtual host regenerated.");

    return 0;
})->purpose('Regenerate Nginx virtual host for a domain with a specified application profile');

Schedule::command('ssl:renew-expiring')->daily();
Schedule::command('domains:verify-pending')->everyFifteenMinutes();
Schedule::command('usage:collect')->everyFifteenMinutes();
Schedule::command('bandwidth:reset')->monthlyOn(1, '00:00');
Schedule::command('backups:run-scheduled')->everyMinute();
Schedule::command('api:prune-logs')->daily();
Schedule::command('pma:prune-sessions')->everyTenMinutes();
