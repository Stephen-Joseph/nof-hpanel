<?php

namespace App\Livewire\Domains;

use App\Jobs\SyncProtectedDirectoryJob;
use App\Models\Account;
use App\Models\Domain;
use App\Models\ProtectedDirectory;
use App\Services\HtpasswdHelper;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class ProtectedDirectoryManager extends Component
{
    public Account $account;

    public ?int $selectedDomainId = null;

    // Create modal & fields
    public bool $showCreateModal = false;

    public ?int $domain_id = null;

    public string $path = '/admin';

    public string $realm = 'Restricted Area';

    public string $new_username = 'admin';

    public string $new_password = '';

    // Add User Modal
    public bool $showAddUserModal = false;

    public ?int $targetDirectoryId = null;

    public string $extra_username = '';

    public string $extra_password = '';

    public function mount(Account $account, ?int $domain_id = null): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['server', 'domains.protectedDirectories']);

        if ($domain_id) {
            $this->selectedDomainId = $domain_id;
            $this->domain_id = $domain_id;
        } else {
            $this->domain_id = $account->domains->first()?->id;
        }
    }

    public function openCreateModal(): void
    {
        $this->new_password = Str::password(12);
        $this->showCreateModal = true;
    }

    public function createDirectory(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'domain_id' => ['required', 'exists:domains,id'],
            'path' => ['required', 'string', 'starts_with:/', 'max:120'],
            'realm' => ['required', 'string', 'max:80'],
            'new_username' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'new_password' => ['required', 'string', 'min:6'],
        ]);

        /** @var Domain $domain */
        $domain = $this->account->domains()->findOrFail($this->domain_id);

        $cleanPath = '/'.trim($this->path, '/');
        $slug = Str::slug($cleanPath);
        $filename = "{$domain->domain}_{$slug}_".Str::lower(Str::random(6)).'.htpasswd';

        $hashedPassword = HtpasswdHelper::hash($this->new_password);

        /** @var ProtectedDirectory $directory */
        $directory = $domain->protectedDirectories()->create([
            'path' => $cleanPath,
            'realm' => trim($this->realm),
            'htpasswd_filename' => $filename,
            'users' => [
                [
                    'username' => trim($this->new_username),
                    'hash' => $hashedPassword,
                ],
            ],
            'is_active' => true,
        ]);

        SyncProtectedDirectoryJob::dispatch($directory);

        $this->showCreateModal = false;
        $this->account->load('domains.protectedDirectories');

        session()->flash('success', "Directory '{$cleanPath}' is now password protected.");
    }

    public function openAddUserModal(int $directoryId): void
    {
        $this->targetDirectoryId = $directoryId;
        $this->extra_username = '';
        $this->extra_password = Str::password(12);
        $this->showAddUserModal = true;
    }

    public function addUser(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'extra_username' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'extra_password' => ['required', 'string', 'min:6'],
        ]);

        if (! $this->targetDirectoryId) {
            return;
        }

        /** @var ProtectedDirectory $dir */
        $dir = ProtectedDirectory::whereHas('domain', function ($q) {
            $q->where('account_id', $this->account->id);
        })->findOrFail($this->targetDirectoryId);

        $users = $dir->users ?? [];
        $users[] = [
            'username' => trim($this->extra_username),
            'hash' => HtpasswdHelper::hash($this->extra_password),
        ];

        $dir->update(['users' => $users]);
        SyncProtectedDirectoryJob::dispatch($dir);

        $this->showAddUserModal = false;
        $this->account->load('domains.protectedDirectories');

        session()->flash('success', "User '{$this->extra_username}' added to protected directory.");
    }

    public function removeUser(int $directoryId, string $username): void
    {
        $this->authorize('view', $this->account);

        /** @var ProtectedDirectory $dir */
        $dir = ProtectedDirectory::whereHas('domain', function ($q) {
            $q->where('account_id', $this->account->id);
        })->findOrFail($directoryId);

        $users = collect($dir->users)->reject(fn ($u) => $u['username'] === $username)->values()->all();

        $dir->update(['users' => $users]);
        SyncProtectedDirectoryJob::dispatch($dir);

        $this->account->load('domains.protectedDirectories');
        session()->flash('success', "User '{$username}' removed.");
    }

    public function toggleActive(int $directoryId): void
    {
        $this->authorize('view', $this->account);

        /** @var ProtectedDirectory $dir */
        $dir = ProtectedDirectory::whereHas('domain', function ($q) {
            $q->where('account_id', $this->account->id);
        })->findOrFail($directoryId);

        $dir->update(['is_active' => ! $dir->is_active]);
        SyncProtectedDirectoryJob::dispatch($dir);

        $this->account->load('domains.protectedDirectories');
    }

    public function deleteDirectory(int $directoryId): void
    {
        $this->authorize('view', $this->account);

        /** @var ProtectedDirectory $dir */
        $dir = ProtectedDirectory::whereHas('domain', function ($q) {
            $q->where('account_id', $this->account->id);
        })->findOrFail($directoryId);

        $dir->update(['is_active' => false, 'users' => []]);
        SyncProtectedDirectoryJob::dispatchSync($dir);

        $dir->delete();

        $this->account->load('domains.protectedDirectories');
        session()->flash('success', 'Protected directory configuration removed.');
    }

    public function render(): View
    {
        $directories = ProtectedDirectory::whereHas('domain', function ($q) {
            $q->where('account_id', $this->account->id);
            if ($this->selectedDomainId) {
                $q->where('id', $this->selectedDomainId);
            }
        })->with('domain')->get();

        return view('livewire.domains.protected-directory-manager', [
            'directories' => $directories,
        ]);
    }
}
