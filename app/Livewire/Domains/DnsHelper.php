<?php

namespace App\Livewire\Domains;

use App\Jobs\IssueSslCertificateJob;
use App\Models\Domain;
use App\Services\DomainVerificationResult;
use App\Services\DomainVerificationService;
use Illuminate\View\View;
use Livewire\Component;

class DnsHelper extends Component
{
    public Domain $domain;

    public ?string $checkMessage = null;

    public ?bool $checkSuccess = null;

    public bool $hasChecked = false;

    public function mount(Domain $domain): void
    {
        $this->authorize('verify', $domain);
        $this->domain = $domain->load(['account.server', 'account.user', 'certificate']);
    }

    public function verifyDns(DomainVerificationService $service): void
    {
        $this->authorize('verify', $this->domain);

        /** @var DomainVerificationResult $result */
        $result = $service->verify($this->domain);

        $this->domain->refresh();
        $this->checkSuccess = $result->verified;
        $this->checkMessage = $result->message;

        if ($result->verified) {
            session()->flash('status', $result->message);
        } else {
            session()->flash('error', $result->message);
        }
    }

    public function checkDns(DomainVerificationService $service): void
    {
        $this->verifyDns($service);
        $this->hasChecked = true;
    }

    public function enableSsl(): void
    {
        $this->authorize('requestSsl', $this->domain);

        if ($this->domain->verification_status !== 'verified') {
            session()->flash('error', 'Domain must be verified before requesting an SSL certificate.');

            return;
        }

        $email = $this->domain->account->user->email ?? 'admin@'.config('app.domain', 'hpanel.local');

        IssueSslCertificateJob::dispatch($this->domain, $email);

        $this->domain->refresh();

        session()->flash('status', "SSL certificate request initiated for {$this->domain->domain}.");
    }

    public function render(): View
    {
        $this->domain->refresh();

        return view('livewire.domains.dns-helper', [
            'edgeHost' => config('hpanel.edge_host', 'edge.nexorafox.in'),
            'txtHost' => $this->domain->getVerificationTxtHost(),
            'txtValue' => $this->domain->getVerificationTxtValue(),
        ]);
    }
}
