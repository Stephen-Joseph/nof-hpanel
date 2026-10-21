<?php

namespace App\Livewire\Files;

use App\Models\Account;
use App\Services\AgentClient;
use Illuminate\View\View;
use Livewire\Component;

class FileManager extends Component
{
    public Account $account;

    public string $currentPath = '';

    public ?string $parentPath = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $entries = [];

    // Editor modal state
    public bool $isEditing = false;

    public string $editingFilePath = '';

    public string $editingFileName = '';

    public string $editingContent = '';

    // Create item modal state
    public bool $isCreating = false;

    public string $newItemName = '';

    public bool $newItemIsDir = false;

    // Rename state
    public bool $isRenaming = false;

    public string $renamingTarget = '';

    public string $renamingNewName = '';

    // Permissions (chmod) state
    public bool $isChmodding = false;

    public string $chmodTarget = '';

    public string $chmodMode = '0644';

    public function mount(Account $account, AgentClient $client): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load('server');

        $this->loadDirectory('', $client);
    }

    public function loadDirectory(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.list',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $data = $response['data'];
                $this->currentPath = $data['rel_path'] ?? '';
                $this->parentPath = ! empty($data['parent_path']) ? $data['parent_path'] : null;
                $this->entries = $data['entries'] ?? [];
            } else {
                session()->flash('error', $response['error'] ?? 'Failed reading directory.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'File Manager error: '.$e->getMessage());
        }
    }

    public function editFile(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.read',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->editingFilePath = $path;
                $this->editingFileName = basename($path);
                $this->editingContent = $response['data']['content'] ?? '';
                $this->isEditing = true;
            } else {
                session()->flash('error', $response['error'] ?? 'Could not read file.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Read error: '.$e->getMessage());
        }
    }

    public function saveFile(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.write',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $this->editingFilePath,
                    'content' => $this->editingContent,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isEditing = false;
                session()->flash('status', "File '{$this->editingFileName}' saved successfully.");
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Failed saving file.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Save error: '.$e->getMessage());
        }
    }

    public function openCreateModal(bool $isDir = false): void
    {
        $this->newItemName = '';
        $this->newItemIsDir = $isDir;
        $this->isCreating = true;
    }

    public function createItem(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'newItemName' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/', 'max:128'],
        ]);

        $fullPath = $this->currentPath ? $this->currentPath.'/'.$this->newItemName : $this->newItemName;

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.create',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $fullPath,
                    'is_dir' => $this->newItemIsDir,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isCreating = false;
                $this->newItemName = '';
                session()->flash('status', ($this->newItemIsDir ? 'Directory' : 'File').' created successfully.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Creation failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Create error: '.$e->getMessage());
        }
    }

    public function deleteItem(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.delete',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                session()->flash('status', 'Item deleted.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Delete failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Delete error: '.$e->getMessage());
        }
    }

    public function openRenameModal(string $path): void
    {
        $this->renamingTarget = $path;
        $this->renamingNewName = basename($path);
        $this->isRenaming = true;
    }

    public function renameItem(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'renamingNewName' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/', 'max:128'],
        ]);

        $targetDir = dirname($this->renamingTarget);
        $newPath = $targetDir.'/'.$this->renamingNewName;

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.rename',
                payload: [
                    'username' => $this->account->linux_username,
                    'source_path' => $this->renamingTarget,
                    'target_path' => $newPath,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isRenaming = false;
                session()->flash('status', 'Item renamed.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Rename failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Rename error: '.$e->getMessage());
        }
    }

    public function openChmodModal(string $path, string $currentMode): void
    {
        $this->chmodTarget = $path;
        $this->chmodMode = $currentMode;
        $this->isChmodding = true;
    }

    public function chmodItem(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'chmodMode' => ['required', 'regex:/^0?[0-7]{3}$/'],
        ]);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.chmod',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $this->chmodTarget,
                    'mode' => $this->chmodMode,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isChmodding = false;
                session()->flash('status', 'Permissions updated.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Chmod failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Chmod error: '.$e->getMessage());
        }
    }

    public function extractZip(string $zipPath, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        $destDir = dirname($zipPath);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.extract',
                payload: [
                    'username' => $this->account->linux_username,
                    'zip_path' => $zipPath,
                    'destination_dir' => $destDir,
                ],
                dryRun: false
            );

            if ($response['success']) {
                session()->flash('status', 'Archive extracted successfully.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Extract failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Extract error: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.files.file-manager');
    }
}

