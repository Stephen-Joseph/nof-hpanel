<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Jobs\IssueSslCertificateJob;
use App\Models\Certificate;
use Illuminate\Console\Command;

class RenewExpiringCertificates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ssl:renew-expiring {--days=30 : Window in days to consider a certificate expiring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically renew Let\'s Encrypt SSL certificates expiring within the threshold window.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = now()->addDays($days);

        $expiringCerts = Certificate::with(['domain.account.user'])
            ->where('type', CertificateType::LETS_ENCRYPT)
            ->where('status', CertificateStatus::ACTIVE)
            ->where('auto_renew', true)
            ->whereNotNull('valid_to')
            ->where('valid_to', '<=', $threshold)
            ->get();

        $this->info(sprintf('Found %d Let\'s Encrypt certificate(s) expiring within %d days.', $expiringCerts->count(), $days));

        foreach ($expiringCerts as $cert) {
            $domain = $cert->domain;
            $email = $domain->account->user->email ?? 'admin@'.config('app.domain', 'hpanel.local');

            $this->line(sprintf('Enqueuing renewal for %s (expires: %s)', $domain->domain, $cert->valid_to?->toDateString()));

            IssueSslCertificateJob::dispatch($domain, $email);
        }

        $this->info('Certificate renewal process dispatched successfully.');

        return Command::SUCCESS;
    }
}

