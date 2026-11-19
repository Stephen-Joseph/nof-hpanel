<?php

namespace App\Livewire\Domains;

use App\Jobs\IssueSslCertificateJob;
use App\Models\Domain;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\DomainZoneResolver;
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

    public ?array $lastDetails = null;

    public function mount(Domain $domain): void
    {
        $this->authorize('verify', $domain);
        $this->domain = $domain->load(['account.server', 'account.user', 'certificate']);
        $this->lastDetails = $this->domain->dns_details;
    }

    public function verifyDns(DomainVerificationService $service): void
    {
        $this->authorize('verify', $this->domain);

        /** @var DomainVerificationResult $result */
        $result = $service->verify($this->domain);

        $this->domain->refresh();
        $this->checkSuccess = $result->verified;
        $this->checkMessage = $result->message;
        $this->lastDetails = $result->details;

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

        if ($this->domain->ssl_status === 'failed' && $this->domain->ssl_requested_at && $this->domain->ssl_requested_at->diffInMinutes(now()) < 60) {
            session()->flash('error', 'SSL validation failed recently for this domain. Please wait at least 1 hour between attempts.');

            return;
        }

        $email = $this->domain->account->user->email ?? 'admin@'.config('app.domain', 'hpanel.local');

        $this->domain->update([
            'ssl_requested_at' => now(),
            'ssl_status' => 'pending',
        ]);

        IssueSslCertificateJob::dispatch($this->domain, $email);

        $this->domain->refresh();

        session()->flash('status', "SSL certificate request initiated for {$this->domain->domain}.");
    }

    public function render(DnsResolverInterface $resolver, DomainZoneResolver $zoneResolver): View
    {
        $this->domain->refresh();
        $this->domain->loadMissing('account.server');
        $node = $this->domain->account?->server;

        $edgeHost = (string) ($node?->edge_host ?: config('hpanel.edge_host', 'edge.nexorafox.in'));

        $edgeIps = [];
        try {
            $aRecords = $resolver->getRecords($edgeHost, DNS_A);
            foreach ($aRecords as $record) {
                if (! empty($record['ip'])) {
                    $edgeIps[] = (string) $record['ip'];
                }
            }
        } catch (\Throwable) {
            $edgeIps = [];
        }

        if (empty($edgeIps)) {
            $fallbackIp = config('hpanel.edge_fallback_ip') ?: $node?->public_ip;
            if (! empty($fallbackIp)) {
                $edgeIps[] = (string) $fallbackIp;
            }
        }

        $nodeIps = [];
        if (! empty($node?->public_ip)) {
            $nodeIps[] = (string) $node->public_ip;
        }

        $zoneInfo = $zoneResolver->resolve($this->domain->domain);

        return view('livewire.domains.dns-helper', [
            'node' => $node,
            'edgeHost' => $edgeHost,
            'edgeIps' => array_values(array_unique($edgeIps)),
            'nodeIps' => array_values(array_unique($nodeIps)),
            'zoneInfo' => $zoneInfo,
            'txtHost' => $zoneInfo['txt_full_name'],
            'txtRelativeHost' => $zoneInfo['txt_relative_host'],
            'txtValue' => $this->domain->getVerificationTxtValue(),
            'cnameRelativeHost' => $zoneInfo['cname_relative_host'],
            'cnameFullName' => $zoneInfo['cname_full_name'],
            'dnsDetails' => $this->lastDetails ?? $this->domain->dns_details ?? [],
        ]);
    }
}
