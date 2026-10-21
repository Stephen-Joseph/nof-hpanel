<?php

namespace App\Livewire\Domains;

use App\Jobs\InstallCustomCertificateJob;
use App\Jobs\IssueSslCertificateJob;
use App\Jobs\RevokeSslCertificateJob;
use App\Jobs\WriteVhostJob;
use App\Models\Certificate;
use App\Models\Domain;
use Illuminate\View\View;
use Livewire\Component;

class DomainSsl extends Component
{
    public Domain $domain;

    public ?Certificate $certificate = null;

    public bool $force_https = false;

    public bool $hsts_enabled = false;

    public bool $auto_renew = true;

    public string $email = '';

    public string $customCert = '';

    public string $customKey = '';

    public string $customChain = '';

    public function mount(Domain $domain): void
    {
        $this->authorize('view', $domain);

        $this->domain = $domain->load(['account.user', 'account.server', 'certificate']);
        $this->certificate = $this->domain->certificate;

        $this->force_https = $this->domain->force_https;
        if ($this->certificate) {
            $this->hsts_enabled = $this->certificate->hsts_enabled;
            $this->auto_renew = $this->certificate->auto_renew;
        }

        $this->email = $this->domain->account->user->email ?? '';
    }

    public function saveToggles(): void
    {
        $this->authorize('update', $this->domain);

        $this->domain->update([
            'force_https' => $this->force_https,
        ]);

        if ($this->domain->certificate) {
            $this->domain->certificate->update([
                'hsts_enabled' => $this->hsts_enabled,
                'auto_renew' => $this->auto_renew,
            ]);
        }

        WriteVhostJob::dispatch($this->domain);

        session()->flash('status', 'SSL and security settings updated successfully.');
    }

    public function requestLetsEncrypt(): void
    {
        $this->authorize('update', $this->domain);

        $this->validate([
            'email' => ['required', 'email'],
        ]);

        IssueSslCertificateJob::dispatch($this->domain, $this->email);

        session()->flash('status', "Let's Encrypt certificate issuance enqueued for {$this->domain->domain}.");
    }

    public function installCustomCertificate(): void
    {
        $this->authorize('update', $this->domain);

        $this->validate([
            'customCert' => ['required', 'string'],
            'customKey' => ['required', 'string'],
            'customChain' => ['nullable', 'string'],
        ]);

        // Validate certificate and key pairing using PHP OpenSSL
        if (function_exists('openssl_x509_check_private_key')) {
            $check = @openssl_x509_check_private_key($this->customCert, $this->customKey);
            if (! $check) {
                $this->addError('customKey', 'The provided private key does not match the SSL certificate.');

                return;
            }
        }

        InstallCustomCertificateJob::dispatch(
            domain: $this->domain,
            certificate: $this->customCert,
            privateKey: $this->customKey,
            chain: ! empty($this->customChain) ? $this->customChain : null
        );

        $this->customCert = '';
        $this->customKey = '';
        $this->customChain = '';

        session()->flash('status', "Custom SSL certificate installation enqueued for {$this->domain->domain}.");
    }

    public function revokeCertificate(): void
    {
        $this->authorize('update', $this->domain);

        RevokeSslCertificateJob::dispatch($this->domain);

        session()->flash('status', "SSL certificate revocation enqueued for {$this->domain->domain}.");
    }

    public function render(): View
    {
        $this->domain->refresh();
        $this->certificate = $this->domain->certificate;

        return view('livewire.domains.domain-ssl');
    }
}
