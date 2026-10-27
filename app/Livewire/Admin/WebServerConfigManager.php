<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Server;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\View\View;
use Livewire\Component;

class WebServerConfigManager extends Component
{
    public ?int $serverId = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $configs = [];

    public string $search = '';

    public bool $isLoading = false;

    public ?string $activeEditingName = null;

    public string $editingContent = '';

    public string $nginxTestOutput = '';

    public ?bool $nginxTestSuccess = null;

    public bool $showingEditorModal = false;

    public bool $showingPanelConfirmModal = false;

    public bool $showingDisableTypedModal = false;

    public string $typedConfirmDomain = '';

    public ?string $pendingDisableName = null;

    public bool $showingRestoreModal = false;

    public ?string $restoreFileName = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $backupsList = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Server::class);

        $server = Server::where('status', 'online')->first() ?? Server::first();
        if ($server) {
            $this->serverId = $server->id;
            $this->loadConfigs(app(AgentClient::class));
        }
    }

    public function loadConfigs(AgentClient $client): void
    {
        $server = $this->getServer();
        if (! $server) {
            $this->configs = [];
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.list',
                payload: [],
                dryRun: false
            );

            if ($res['success']) {
                $this->configs = $res['data']['files'] ?? [];
            } else {
                $this->errorMessage = $res['error'] ?? 'Failed to list Nginx configurations.';
                $this->configs = [];
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Error connecting to agent: ' . $e->getMessage();
            $this->configs = [];
        }
    }

    public function testGlobalConfig(AgentClient $client): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = '';
        $this->nginxTestSuccess = null;

        $server = $this->getServer();
        if (! $server) {
            $this->errorMessage = 'No server selected.';
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.test',
                payload: [],
                dryRun: false
            );

            $this->nginxTestOutput = $res['data']['nginx_output'] ?? ($res['error'] ?? 'No output received');
            $this->nginxTestSuccess = (bool) ($res['success'] && ($res['data']['valid'] ?? true));

            if ($this->nginxTestSuccess) {
                $this->statusMessage = 'Nginx syntax test: OK.';
            } else {
                $this->errorMessage = 'Nginx syntax test failed.';
            }
        } catch (\Throwable $e) {
            $this->nginxTestOutput = $e->getMessage();
            $this->nginxTestSuccess = false;
            $this->errorMessage = 'Error running syntax test: ' . $e->getMessage();
        }
    }

    public function openEditor(string $name, AgentClient $client): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = '';
        $this->nginxTestSuccess = null;

        $server = $this->getServer();
        if (! $server) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.read',
                payload: ['name' => $name],
                dryRun: false
            );

            if ($res['success']) {
                $this->activeEditingName = $name;
                $this->editingContent = $res['data']['content'] ?? '';
                $this->showingEditorModal = true;
            } else {
                $this->errorMessage = $res['error'] ?? "Failed to read config {$name}.";
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Error reading {$name}: " . $e->getMessage();
        }
    }

    public function closeEditor(): void
    {
        $this->showingEditorModal = false;
        $this->activeEditingName = null;
        $this->editingContent = '';
        $this->nginxTestOutput = '';
        $this->nginxTestSuccess = null;
        $this->showingPanelConfirmModal = false;
    }

    public function initiateSave(AgentClient $client): void
    {
        $panelDomain = config('hpanel.panel_domain');
        $isPanelVhost = ($this->activeEditingName === $panelDomain . '.conf');

        if ($isPanelVhost && ! $this->showingPanelConfirmModal) {
            $this->showingPanelConfirmModal = true;
            return;
        }

        $this->executeSave($client);
    }

    public function confirmPanelSave(AgentClient $client): void
    {
        $this->showingPanelConfirmModal = false;
        $this->executeSave($client);
    }

    protected function executeSave(AgentClient $client): void
    {
        $server = $this->getServer();
        if (! $server || ! $this->activeEditingName) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.write',
                payload: [
                    'name' => $this->activeEditingName,
                    'content' => $this->editingContent,
                    'admin_confirmed' => true,
                ],
                dryRun: false
            );

            $this->nginxTestOutput = $res['data']['nginx_output'] ?? '';

            if ($res['success']) {
                $this->nginxTestSuccess = true;

                // Audit log without configuration content
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'nginx.config.write',
                    'target_type' => Server::class,
                    'target_id' => $server->id,
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'new_values' => [
                        'file' => $this->activeEditingName,
                        'server_id' => $server->id,
                    ],
                ]);

                $this->loadConfigs($client);
                $this->statusMessage = "Configuration '{$this->activeEditingName}' saved and Nginx reloaded successfully.";
            } else {
                $this->nginxTestSuccess = false;
                $this->errorMessage = 'Nginx test failed. Your edits have been automatically rolled back.';
            }
        } catch (\Throwable $e) {
            $this->nginxTestSuccess = false;
            $this->nginxTestOutput = $e->getMessage();
            $this->errorMessage = 'Failed to save config: ' . $e->getMessage();
        }
    }

    public function enableConfig(string $name, AgentClient $client): void
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.enable',
                payload: ['name' => $name],
                dryRun: false
            );

            if ($res['success']) {
                $this->statusMessage = "Configuration '{$name}' enabled successfully.";

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'nginx.config.enable',
                    'target_type' => Server::class,
                    'target_id' => $server->id,
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'new_values' => ['file' => $name],
                ]);

                $this->loadConfigs($client);
            } else {
                $this->errorMessage = $res['error'] ?? "Failed to enable {$name}.";
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Error enabling {$name}: " . $e->getMessage();
        }
    }

    public function initiateDisable(string $name, AgentClient $client): void
    {
        $panelDomain = config('hpanel.panel_domain');
        $isPanelVhost = ($name === $panelDomain . '.conf');

        if ($isPanelVhost) {
            $this->pendingDisableName = $name;
            $this->typedConfirmDomain = '';
            $this->showingDisableTypedModal = true;
            return;
        }

        $this->executeDisable($name, $client);
    }

    public function confirmTypedDisable(AgentClient $client): void
    {
        $panelDomain = (string) config('hpanel.panel_domain');
        if (trim($this->typedConfirmDomain) !== $panelDomain) {
            $this->addError('typedConfirmDomain', "Type '{$panelDomain}' exactly to confirm disabling the panel's own vhost.");
            return;
        }

        $name = $this->pendingDisableName;
        $this->showingDisableTypedModal = false;
        $this->pendingDisableName = null;
        $this->typedConfirmDomain = '';

        if ($name) {
            $this->executeDisable($name, $client);
        }
    }

    protected function executeDisable(string $name, AgentClient $client): void
    {
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.disable',
                payload: ['name' => $name],
                dryRun: false
            );

            if ($res['success']) {
                $this->statusMessage = "Configuration '{$name}' disabled successfully.";

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'nginx.config.disable',
                    'target_type' => Server::class,
                    'target_id' => $server->id,
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'new_values' => ['file' => $name],
                ]);

                $this->loadConfigs($client);
            } else {
                $this->errorMessage = $res['error'] ?? "Failed to disable {$name}.";
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Error disabling {$name}: " . $e->getMessage();
        }
    }

    public function openRestoreModal(string $name, AgentClient $client): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $server = $this->getServer();
        if (! $server) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.backups',
                payload: ['name' => $name],
                dryRun: false
            );

            if ($res['success']) {
                $this->restoreFileName = $name;
                $this->backupsList = $res['data']['backups'] ?? [];
                $this->showingRestoreModal = true;
            } else {
                $this->errorMessage = $res['error'] ?? "Failed to fetch backups for {$name}.";
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Error fetching backups for {$name}: " . $e->getMessage();
        }
    }

    public function closeRestoreModal(): void
    {
        $this->showingRestoreModal = false;
        $this->restoreFileName = null;
        $this->backupsList = [];
    }

    public function restoreBackup(string $backupId, AgentClient $client): void
    {
        $server = $this->getServer();
        if (! $server || ! $this->restoreFileName) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.config.restore',
                payload: [
                    'name' => $this->restoreFileName,
                    'backup_id' => $backupId,
                ],
                dryRun: false
            );

            if ($res['success']) {
                $this->statusMessage = "Restored '{$this->restoreFileName}' from backup '{$backupId}'.";

                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'nginx.config.restore',
                    'target_type' => Server::class,
                    'target_id' => $server->id,
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'new_values' => [
                        'file' => $this->restoreFileName,
                        'backup_id' => $backupId,
                    ],
                ]);

                $this->closeRestoreModal();
                $this->loadConfigs($client);
            } else {
                $this->errorMessage = $res['error'] ?? 'Failed to restore backup.';
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Error restoring backup: ' . $e->getMessage();
        }
    }

    protected function getServer(): ?Server
    {
        if ($this->serverId) {
            return Server::find($this->serverId);
        }
        return Server::where('status', 'online')->first() ?? Server::first();
    }

    public function render(): View
    {
        $filteredConfigs = array_filter($this->configs, function ($c) {
            if (empty($this->search)) {
                return true;
            }
            $search = strtolower($this->search);
            return str_contains(strtolower($c['name'] ?? ''), $search) ||
                   str_contains(strtolower($c['owner'] ?? ''), $search);
        });

        $servers = Server::all();

        return view('livewire.admin.web-server-config-manager', [
            'filteredConfigs' => $filteredConfigs,
            'servers' => $servers,
            'panelDomain' => config('hpanel.panel_domain'),
        ])->layout('components.layouts.app', [
            'title' => 'Web Server Config &middot; ' . config('app.name', 'HPanel'),
        ]);
    }
}
