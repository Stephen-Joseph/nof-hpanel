<?php

namespace App\Providers;

use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\SystemDnsResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolverInterface::class, SystemDnsResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('online_sftp', function (Request $request) {
            $limit = (int) config('hpanel.online_sftp.rate_limit_per_minute', 120);

            return Limit::perMinute($limit)->by((string) ($request->user()?->id ?: $request->ip()));
        });
    }
}
