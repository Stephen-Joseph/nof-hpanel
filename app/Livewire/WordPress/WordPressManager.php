<?php

namespace App\Livewire\WordPress;

use App\Jobs\CreateDatabaseJob;
use App\Jobs\CreateDatabaseUserJob;
use App\Jobs\GrantDatabasePrivilegesJob;
use App\Jobs\InstallWordPressJob;
use App\Jobs\UninstallWordPressJob;
use App\Models\Account;
use App\Models\DatabaseUserGrant;
use App\Models\Domain;
use App\Models\WordPressInstall;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class WordPressManager extends Component
{
    public Account $account;

    // Form fields
    public ?int $domain_id = null;

    public string $install_path = '';

    public string $site_title = 'My WordPress Website';

    public string $admin_user = 'wpadmin';

    public string $admin_password = '';

    public string $admin_email = '';

    public bool $auto_create_db = true;

    public string $db_name = '';

    public string $db_user = '';

    public string $db_password = '';

    public string $table_prefix = 'wp_';

    public string $locale = 'en_US';

    // UI state
    public bool $showInstallModal = false;

    public ?int $installToDeleteId = null;

    public bool $removeDbOnUninstall = true;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'domains', 'wordPressInstalls.domain']);
        $this->admin_email = $account->user->email ?? 'admin@example.com';
        $this->admin_password = Str::password(16);

        if ($firstDomain = $this->account->domains->first()) {
            $this->domain_id = $firstDomain->id;
            $this->install_path = $firstDomain->document_root;
        }
    }

    public function updatedDomainId(?int $value): void
    {
        if ($value) {
            $domain = $this->account->domains->firstWhere('id', $value);
            if ($domain) {
                $this->install_path = $domain->document_root;
            }
        }
    }

    public function openInstallModal(): void
    {
        $this->admin_password = Str::password(16);
        $this->db_password = Str::password(16);

        $suffix = Str::lower(Str::random(4));
        $prefix = substr($this->account->linux_username, 0, 10);
        $this->db_name = "{$prefix}_wp{$suffix}";
        $this->db_user = "{$prefix}_u{$suffix}";

        $this->showInstallModal = true;
    }

    public function install(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'domain_id' => ['required', 'exists:domains,id'],
            'install_path' => ['required', 'string', 'starts_with:/home/'.$this->account->linux_username.'/'],
            'site_title' => ['required', 'string', 'max:120'],
            'admin_user' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'admin_password' => ['required', 'string', 'min:8'],
            'admin_email' => ['required', 'email'],
            'db_name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_user' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_password' => ['required', 'string', 'min:8'],
            'table_prefix' => ['required', 'string', 'max:20', 'regex:/^[a-zA-Z0-9_]+$/'],
            'locale' => ['required', 'string', 'max:20'],
        ]);

        /** @var Domain $domain */
        $domain = $this->account->domains()->findOrFail($this->domain_id);

        if ($this->auto_create_db) {
            // Provision MariaDB database and user in panel database
            $db = $this->account->databases()->create([
                'name' => $this->db_name,
            ]);

            $dbUser = $this->account->databaseUsers()->create([
                'username' => $this->db_user,
            ]);

            /** @var DatabaseUserGrant $grant */
            $grant = DatabaseUserGrant::create([
                'database_id' => $db->id,
                'database_user_id' => $dbUser->id,
                'privileges' => ['ALL PRIVILEGES'],
            ]);

            // Dispatch DB provisioning jobs synchronously or queued
            CreateDatabaseJob::dispatchSync($db);
            CreateDatabaseUserJob::dispatchSync($dbUser, $this->db_password);
            GrantDatabasePrivilegesJob::dispatchSync($grant);
        }

        /** @var WordPressInstall $install */
        $install = $this->account->wordPressInstalls()->create([
            'domain_id' => $domain->id,
            'install_path' => trim($this->install_path),
            'site_title' => trim($this->site_title),
            'admin_email' => trim($this->admin_email),
            'admin_user' => trim($this->admin_user),
            'db_name' => trim($this->db_name),
            'db_user' => trim($this->db_user),
            'table_prefix' => trim($this->table_prefix),
            'locale' => trim($this->locale),
            'status' => 'installing',
        ]);

        InstallWordPressJob::dispatch($install, $this->admin_password, $this->db_password);

        $this->showInstallModal = false;
        $this->account->load('wordPressInstalls.domain');

        session()->flash('success', "WordPress installation dispatched for '{$domain->domain}'. Please allow a moment for setup to complete.");
    }

    public function confirmUninstall(int $installId): void
    {
        $this->installToDeleteId = $installId;
    }

    public function uninstall(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->installToDeleteId) {
            return;
        }

        /** @var WordPressInstall $install */
        $install = $this->account->wordPressInstalls()->findOrFail($this->installToDeleteId);

        UninstallWordPressJob::dispatch($install, $this->removeDbOnUninstall);

        $this->installToDeleteId = null;
        $this->account->load('wordPressInstalls.domain');

        session()->flash('success', 'WordPress uninstall task dispatched.');
    }

    public function render(): View
    {
        return view('livewire.wordpress.word-press-manager');
    }
}
