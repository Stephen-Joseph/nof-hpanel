<?php

namespace App\Livewire\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class ServerList extends Component
{
    public bool $showEnrollModal = false;

    public string $enrollServerName = '';

    public ?int $enrollServerGroupId = null;

    public string $generatedToken = '';

    public string $enrollInstallCommand = '';

    public function toggleDrain(int $id, AuditService $audit): void
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Server|null $server */
        $server = Server::find($id);

        if (! $server) {
            return;
        }

        $this->authorize('update', $server);

        $newStatus = $server->status === ServerStatus::ACTIVE ? ServerStatus::DRAINING : ServerStatus::ACTIVE;
        $oldStatus = $server->status;

        $server->update(['status' => $newStatus]);
        $audit->log('server.status_changed', $server, ['status' => $oldStatus->value], ['status' => $newStatus->value], $user);

        session()->flash('status', "Server {$server->name} status set to {$newStatus->label()}.");
    }

    public function openEnrollModal(): void
    {
        $this->enrollServerName = '';
        $this->enrollServerGroupId = null;
        $this->generatedToken = '';
        $this->enrollInstallCommand = '';
        $this->showEnrollModal = true;
    }

    public function generateEnrollToken(): void
    {
        $this->validate([
            'enrollServerName' => ['required', 'string', 'max:100'],
            'enrollServerGroupId' => ['nullable', 'exists:server_groups,id'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $tokenStr = Str::random(64);

        ServerEnrollmentToken::create([
            'token' => $tokenStr,
            'server_name' => trim($this->enrollServerName),
            'server_group_id' => $this->enrollServerGroupId,
            'created_by' => $user->id,
            'expires_at' => now()->addHours(24),
        ]);

        $this->generatedToken = $tokenStr;
        $this->enrollInstallCommand = sprintf(
            'curl -sSL %s/deploy/enroll.sh | sudo bash -s -- --token %s --panel %s',
            config('app.url'),
            $tokenStr,
            config('app.url')
        );

        session()->flash('enroll_success', 'One-time enrollment token generated. Valid for 24 hours.');
    }

    public function deleteServer(int $id, AuditService $audit): void
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Server|null $server */
        $server = Server::find($id);

        if (! $server) {
            return;
        }

        $this->authorize('delete', $server);

        if ($server->accounts()->exists()) {
            session()->flash('error', 'Cannot remove server because active accounts are currently hosted on it.');

            return;
        }

        $audit->log('server.deleted', $server, $server->toArray(), null, $user);
        $server->delete();

        session()->flash('status', 'Server node successfully removed.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::withCount('accounts')
            ->with('serverGroup')
            ->orderBy('name')
            ->get();

        $serverGroups = ServerGroup::orderBy('name')->get();

        return view('livewire.servers.server-list', [
            'servers' => $servers,
            'serverGroups' => $serverGroups,
        ]);
    }
}
