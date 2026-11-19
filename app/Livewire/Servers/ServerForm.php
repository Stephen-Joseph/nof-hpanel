<?php

namespace App\Livewire\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Dns\DnsResolverInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class ServerForm extends Component
{
    public ?Server $server = null;

    public bool $isEditing = false;

    public string $name = '';

    public string $hostname = '';

    public string $private_ip = '';

    public string $public_ip = '';

    public ?string $public_ipv6 = null;

    public string $edge_host = '';

    public ?bool $edgeDnsOk = null;

    public ?string $edgeDnsMessage = null;

    public string $agent_url = '';

    public string $status = 'active';

    public ?string $agent_public_cert_fingerprint = null;

    public int $max_accounts = 100;

    public ?int $cpu_cores = null;

    public ?int $memory_mb = null;

    public ?int $storage_mb = null;

    public function mount(?Server $server = null): void
    {
        if ($server && $server->exists) {
            $this->authorize('update', $server);
            $this->server = $server;
            $this->isEditing = true;

            $this->name = $server->name;
            $this->hostname = $server->hostname;
            $this->private_ip = $server->private_ip;
            $this->public_ip = (string) $server->public_ip;
            $this->public_ipv6 = $server->public_ipv6;
            $this->edge_host = (string) $server->edge_host;
            $this->edgeDnsOk = $server->edge_dns_ok;
            $this->agent_url = $server->agent_url;
            $this->status = $server->status->value;
            $this->agent_public_cert_fingerprint = $server->agent_public_cert_fingerprint;
            $this->max_accounts = $server->max_accounts;
            $this->cpu_cores = $server->cpu_cores;
            $this->memory_mb = $server->memory_mb;
            $this->storage_mb = $server->storage_mb;
        } else {
            $this->authorize('create', Server::class);
            $this->agent_url = 'https://10.0.0.1:8443';
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        $serverId = $this->server?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'hostname' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('servers', 'hostname')->ignore($serverId),
            ],
            'private_ip' => [
                'required',
                'ip',
                Rule::unique('servers', 'private_ip')->ignore($serverId),
            ],
            'public_ip' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (! Server::isPublicIpv4($value)) {
                        $fail("The {$attribute} must be a valid public IPv4 address and cannot be in private or reserved ranges.");
                    }
                },
            ],
            'public_ipv6' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (! empty($value) && ! Server::isValidIpv6($value)) {
                        $fail("The {$attribute} must be a valid IPv6 address.");
                    }
                },
            ],
            'edge_host' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
                Rule::unique('servers', 'edge_host')->ignore($serverId),
            ],
            'agent_url' => ['required', 'url'],
            'status' => ['required', Rule::enum(ServerStatus::class)],
            'agent_public_cert_fingerprint' => ['nullable', 'string', 'size:64'],
            'max_accounts' => ['required', 'integer', 'min:1', 'max:10000'],
            'cpu_cores' => ['nullable', 'integer', 'min:1', 'max:256'],
            'memory_mb' => ['nullable', 'integer', 'min:512'],
            'storage_mb' => ['nullable', 'integer', 'min:1024'],
        ];
    }

    public function checkEdgeDns(DnsResolverInterface $resolver): void
    {
        $this->validateOnly('edge_host');
        $this->validateOnly('public_ip');

        $host = trim($this->edge_host);
        if (empty($host)) {
            $host = Server::generateDefaultEdgeHost($this->name ?: 'node');
            $this->edge_host = $host;
        }

        $records = $resolver->getRecords($host, DNS_A);
        $resolvedIps = [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $resolvedIps[] = (string) $r['ip'];
            }
        }
        $resolvedIps = array_values(array_unique($resolvedIps));

        $target = trim($this->public_ip);
        if (in_array($target, $resolvedIps, true)) {
            $this->edgeDnsOk = true;
            $this->edgeDnsMessage = "Edge DNS is pointing to {$target}.";
        } else {
            $this->edgeDnsOk = false;
            $foundStr = empty($resolvedIps) ? 'unresolved' : implode(', ', $resolvedIps);
            $this->edgeDnsMessage = "Edge DNS does not match! Expected: {$target}, Resolved: {$foundStr}.";
        }

        if ($this->server && $this->server->exists) {
            $this->server->update([
                'edge_dns_checked_at' => now(),
                'edge_dns_ok' => $this->edgeDnsOk,
            ]);
        }
    }

    public function save(AuditService $audit): RedirectResponse
    {
        $validated = $this->validate();

        if (empty($validated['edge_host'])) {
            $validated['edge_host'] = Server::generateDefaultEdgeHost($validated['name']);
        }

        /** @var User $actor */
        $actor = Auth::user();

        if ($this->isEditing && $this->server) {
            $this->authorize('update', $this->server);
            $oldValues = $this->server->toArray();
            $this->server->update($validated);
            $audit->log('server.updated', $this->server, $oldValues, $this->server->toArray(), $actor);
            session()->flash('status', 'Server node updated successfully.');
        } else {
            $this->authorize('create', Server::class);
            $server = Server::create($validated);
            $audit->log('server.created', $server, null, $server->toArray(), $actor);
            session()->flash('status', 'Server node registered successfully.');
        }

        return redirect()->route('servers.index');
    }

    public function render(): View
    {
        return view('livewire.servers.server-form', [
            'statuses' => ServerStatus::cases(),
        ]);
    }
}
