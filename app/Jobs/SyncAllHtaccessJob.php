<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllHtaccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Domain::with(['account.server'])
            ->where('is_active', true)
            ->whereHas('account.server', function ($query) {
                $query->where('status', ServerStatus::ACTIVE);
            })
            ->chunk(50, function ($domains) {
                foreach ($domains as $domain) {
                    SyncHtaccessRulesJob::dispatch($domain);
                }
            });
    }
}
