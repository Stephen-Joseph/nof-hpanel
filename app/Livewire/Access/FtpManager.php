<?php

namespace App\Livewire\Access;

use App\Models\Account;
use App\Models\FtpAccount;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Livewire\Component;

class FtpManager extends Component
{
    public Account $account;

    public string $subuser = '';

    public string $homedir = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $isCreating = false;

    // Primary Account SFTP Password Modal
    public bool $isSettingPrimaryPassword = false;

    public string $primaryPassword = '';

    public ?string $generatedPassword = null;

    public bool $showPasswordModal = false;

    // SFTP Login Diagnostic
    public ?array $sftpDiagnostic = null;

    public ?string $diagnosticError = null;

    public bool $showDiagnosticModal = false;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'ftpAccounts']);
        $this->homedir = "/home/{$account->linux_username}/public_html";
    }

    public function openPrimaryPasswordModal(): void
    {
        $this->authorize('view', $this->account);
        $this->primaryPassword = \Illuminate\Support\Str::password(16);
        $this->isSettingPrimaryPassword = true;
    }

    public function closePrimaryPasswordModal(): void
    {
        $this->isSettingPrimaryPassword = false;
        $this->primaryPassword = '';
    }

    public function savePrimaryPassword(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'primaryPassword' => ['required', 'string', 'min:12'],
        ]);

        try {
            // 1. Ensure SFTP match block exists
            $enableRes = $client->execute(
                server: $this->account->server,
                action: 'sftp.enable',
                payload: [
                    'username' => $this->account->linux_username,
                ],
                dryRun: false
            );

            if (! ($enableRes['success'] ?? false)) {
                $this->addError('primaryPassword', $enableRes['error'] ?? 'Failed to enable SFTP on server.');
                return;
            }

            // 2. Set system password via chpasswd
            $passRes = $client->execute(
                server: $this->account->server,
                action: 'sftp.set_password',
                payload: [
                    'username' => $this->account->linux_username,
                    'password' => $this->primaryPassword,
                ],
                dryRun: false
            );

            if (! ($passRes['success'] ?? false)) {
                $this->addError('primaryPassword', $passRes['error'] ?? 'Failed to update SFTP password.');
                return;
            }

            $this->generatedPassword = $this->primaryPassword;
            $this->showPasswordModal = true;
            $this->isSettingPrimaryPassword = false;
            $this->primaryPassword = '';

            session()->flash('status', "SFTP access enabled and password set for {$this->account->linux_username}.");
        } catch (\Throwable $e) {
            $this->addError('primaryPassword', 'Error: ' . $e->getMessage());
        }
    }

    public function closeShowPasswordModal(): void
    {
        $this->showPasswordModal = false;
        $this->generatedPassword = null;
    }

    public function runDiagnostic(AgentClient $client): void
    {
        $this->authorize('view', $this->account);
        $this->sftpDiagnostic = null;
        $this->diagnosticError = null;
        $this->showDiagnosticModal = true;

        try {
            $res = $client->execute(
                server: $this->account->server,
                action: 'sshd.effective',
                payload: [
                    'username' => $this->account->linux_username,
                ],
                dryRun: false
            );

            if ($res['success'] ?? false) {
                $this->sftpDiagnostic = $res['data'] ?? [];
            } else {
                $this->diagnosticError = $res['error'] ?? 'Failed to evaluate sshd settings.';
            }
        } catch (\Throwable $e) {
            $this->diagnosticError = 'Diagnostic execution failed: ' . $e->getMessage();
        }
    }

    public function closeDiagnosticModal(): void
    {
        $this->showDiagnosticModal = false;
        $this->sftpDiagnostic = null;
        $this->diagnosticError = null;
    }

    public function openCreateModal(): void
    {
        $this->subuser = '';
        $this->homedir = "/home/{$this->account->linux_username}/public_html";
        $this->password = '';
        $this->password_confirmation = '';
        $this->isCreating = true;
    }

    public function createFtpAccount(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $maxAllowed = $this->account->package->max_ftp_accounts ?? 5;
        if ($this->account->ftpAccounts()->count() >= $maxAllowed) {
            $this->addError('subuser', "Package limit reached (Max: {$maxAllowed} FTP/SFTP accounts).");

            return;
        }

        $this->validate([
            'subuser' => ['required', 'string', 'min:3', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'homedir' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $fullUsername = "{$this->account->linux_username}_{$this->subuser}";
        $expectedHome = "/home/{$this->account->linux_username}";

        // Enforce path containment strictly inside account home
        if (str_contains($this->homedir, '..') || str_contains($this->homedir, "\0")) {
            $this->addError('homedir', 'Homedir contains illegal path traversal characters.');

            return;
        }

        if ($this->homedir !== $expectedHome && ! str_starts_with($this->homedir, $expectedHome.'/')) {
            $this->addError('homedir', "Homedir must reside inside {$expectedHome}/");

            return;
        }

        if (FtpAccount::where('username', $fullUsername)->exists()) {
            $this->addError('subuser', "Username '{$fullUsername}' is already taken.");

            return;
        }

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'sftp.account_create',
                payload: [
                    'parent_user' => $this->account->linux_username,
                    'subuser' => $fullUsername,
                    'homedir' => $this->homedir,
                    'password' => $this->password,
                ],
                dryRun: false
            );

            if ($response['success']) {
                FtpAccount::create([
                    'account_id' => $this->account->id,
                    'username' => $fullUsername,
                    'homedir' => $this->homedir,
                    'password_hash' => Hash::make($this->password),
                    'is_active' => true,
                ]);

                $this->isCreating = false;
                session()->flash('status', "SFTP sub-account '{$fullUsername}' created successfully.");
            } else {
                $this->addError('subuser', $response['error'] ?? 'Agent failed creating SFTP account.');
            }
        } catch (\Throwable $e) {
            $this->addError('subuser', 'Error: '.$e->getMessage());
        }
    }

    public function toggleStatus(int $id): void
    {
        $this->authorize('view', $this->account);

        $ftp = $this->account->ftpAccounts()->findOrFail($id);
        $ftp->update(['is_active' => ! $ftp->is_active]);

        session()->flash('status', "SFTP account '{$ftp->username}' status toggled.");
    }

    public function deleteFtpAccount(int $id): void
    {
        $this->authorize('view', $this->account);

        $ftp = $this->account->ftpAccounts()->findOrFail($id);
        $ftp->delete();

        session()->flash('status', "SFTP account '{$ftp->username}' removed.");
    }

    public function render(): View
    {
        return view('livewire.access.ftp-manager', [
            'accounts' => $this->account->ftpAccounts()->orderBy('username')->get(),
        ]);
    }
}
