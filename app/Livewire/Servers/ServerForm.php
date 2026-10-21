<?php

namespace App\Livewire\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditService;
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
            'agent_url' => ['required', 'url'],
            'status' => ['required', Rule::enum(ServerStatus::class)],
            'agent_public_cert_fingerprint' => ['nullable', 'string', 'size:64'],
            'max_accounts' => ['required', 'integer', 'min:1', 'max:10000'],
            'cpu_cores' => ['nullable', 'integer', 'min:1', 'max:256'],
            'memory_mb' => ['nullable', 'integer', 'min:512'],
            'storage_mb' => ['nullable', 'integer', 'min:1024'],
        ];
    }

    public function save(AuditService $audit): RedirectResponse
    {
        $validated = $this->validate();

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
