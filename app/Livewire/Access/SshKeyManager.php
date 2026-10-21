<?php

namespace App\Livewire\Access;

use App\Models\Account;
use App\Models\SshKey;
use App\Services\AgentClient;
use Illuminate\View\View;
use Livewire\Component;

class SshKeyManager extends Component
{
    public Account $account;

    public string $name = '';

    public string $publicKey = '';

    public bool $isCreating = false;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'sshKeys']);
    }

    public function openCreateModal(): void
    {
        $this->name = '';
        $this->publicKey = '';
        $this->isCreating = true;
    }

    public function addKey(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'name' => ['required', 'string', 'max:64'],
            'publicKey' => ['required', 'string', 'min:20'],
        ]);

        $cleanKey = trim($this->publicKey);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'ssh.key_add',
                payload: [
                    'username' => $this->account->linux_username,
                    'public_key' => $cleanKey,
                    'name' => $this->name,
                    'ssh_enabled' => (bool) ($this->account->package->ssh_enabled ?? false),
                ],
                dryRun: false
            );

            if ($response['success']) {
                $data = $response['data'];

                SshKey::create([
                    'account_id' => $this->account->id,
                    'name' => $this->name,
                    'key_type' => $data['key_type'] ?? 'ssh-ed25519',
                    'public_key' => $cleanKey,
                    'fingerprint' => $data['fingerprint'] ?? 'SHA256:unknown',
                ]);

                $savedName = $this->name;
                $this->isCreating = false;
                $this->name = '';
                $this->publicKey = '';

                session()->flash('status', "SSH key '{$savedName}' added successfully.");
            } else {
                $this->addError('publicKey', $response['error'] ?? 'Agent failed to validate SSH key.');
            }
        } catch (\Throwable $e) {
            $this->addError('publicKey', 'Error: '.$e->getMessage());
        }
    }

    public function deleteKey(int $id, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $key = $this->account->sshKeys()->findOrFail($id);

        try {
            $client->execute(
                server: $this->account->server,
                action: 'ssh.key_remove',
                payload: [
                    'username' => $this->account->linux_username,
                    'public_key' => $key->public_key,
                ],
                dryRun: false
            );

            $key->delete();
            session()->flash('status', "SSH key '{$key->name}' removed.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Failed removing SSH key: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.access.ssh-key-manager', [
            'keys' => $this->account->sshKeys()->orderByDesc('created_at')->get(),
        ]);
    }
}
