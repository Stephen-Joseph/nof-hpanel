<?php

namespace App\Livewire\Domains;

use App\Models\Domain;
use Illuminate\View\View;
use Livewire\Component;

class DnsHelper extends Component
{
    public Domain $domain;

    public ?string $resolvedIp = null;

    public ?string $checkStatus = null;

    public bool $hasChecked = false;

    public function mount(Domain $domain): void
    {
        $this->authorize('view', $domain);
        $this->domain = $domain->load('account.server');
    }

    public function checkDns(): void
    {
        $this->hasChecked = true;
        $hostname = $this->domain->domain;

        $records = @dns_get_record($hostname, DNS_A);
        if (! empty($records)) {
            $this->resolvedIp = $records[0]['ip'] ?? null;
        } else {
            $this->resolvedIp = null;
        }

        $expectedIp = $this->domain->account->server->private_ip;

        if ($this->resolvedIp === $expectedIp) {
            $this->checkStatus = 'pointing';
        } elseif ($this->resolvedIp !== null) {
            $this->checkStatus = 'mismatch';
        } else {
            $this->checkStatus = 'unresolved';
        }
    }

    public function render(): View
    {
        return view('livewire.domains.dns-helper');
    }
}
