<?php

namespace App\Livewire\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AgentClient;
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

    // SFTP live diagnostic
    public bool $showSftpDiagnosticModal = false;

    public ?int $selectedServerId = null;

    public ?Server $selectedServer = null;

    public string $sftpDiagnosticUsername = '';

    public ?array $sftpDiagnosticResult = null;

    public ?string $sftpDiagnosticError = null;

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

    public function openSftpDiagnosticModal(int $serverId): void
    {
        $server = Server::findOrFail($serverId);
        $this->authorize('view', $server);

        $this->selectedServerId = $server->id;
        $this->selectedServer = $server;

        $firstAccount = $server->accounts()->first();
        $this->sftpDiagnosticUsername = $firstAccount ? $firstAccount->linux_username : 'admin263';

        $this->sftpDiagnosticResult = null;
        $this->sftpDiagnosticError = null;
        $this->showSftpDiagnosticModal = true;
    }

    public function closeSftpDiagnosticModal(): void
    {
        $this->showSftpDiagnosticModal = false;
        $this->selectedServerId = null;
        $this->selectedServer = null;
        $this->sftpDiagnosticResult = null;
        $this->sftpDiagnosticError = null;
        $this->sftpDiagnosticUsername = '';
    }

    public function runSftpDiagnostic(AgentClient $client): void
    {
        if (! $this->selectedServerId) {
            return;
        }

        $server = Server::findOrFail($this->selectedServerId);
        $this->authorize('view', $server);

        $this->validate([
            'sftpDiagnosticUsername' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{0,31}$/'],
        ]);

        $this->sftpDiagnosticResult = null;
        $this->sftpDiagnosticError = null;

        try {
            $res = $client->execute(
                server: $server,
                action: 'sshd.effective',
                payload: [
                    'username' => trim($this->sftpDiagnosticUsername),
                ],
                dryRun: false
            );

            if ($res['success'] ?? false) {
                $this->sftpDiagnosticResult = $res['data'] ?? [];
            } else {
                $this->sftpDiagnosticError = $res['error'] ?? 'Failed to evaluate sshd settings.';
            }
        } catch (\Throwable $e) {
            $this->sftpDiagnosticError = 'Diagnostic execution failed: ' . $e->getMessage();
        }
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
