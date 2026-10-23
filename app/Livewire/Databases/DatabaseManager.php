<?php

namespace App\Livewire\Databases;

use App\Jobs\CreateDatabaseJob;
use App\Jobs\CreateDatabaseUserJob;
use App\Jobs\DropDatabaseJob;
use App\Jobs\DropDatabaseUserJob;
use App\Jobs\ExportDatabaseJob;
use App\Jobs\GrantDatabasePrivilegesJob;
use App\Jobs\RevokeDatabasePrivilegesJob;
use App\Jobs\SyncDatabaseUsageJob;
use App\Jobs\UpdateDatabaseUserPasswordJob;
use App\Models\Account;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DatabaseUserGrant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class DatabaseManager extends Component
{
    public const ALLOWED_PRIVILEGES = [
        'ALL PRIVILEGES',
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'DROP',
        'INDEX',
        'ALTER',
        'CREATE TEMPORARY TABLES',
        'LOCK TABLES',
        'EXECUTE',
        'TRIGGER',
    ];

    public Account $account;

    // Create Database form
    public string $dbSuffix = '';

    // Create Database User form
    public string $userSuffix = '';

    public string $userPassword = '';

    public string $userHost = 'localhost';

    public ?string $generatedPasswordNotice = null;

    // Grant Modal / Assignment form
    public bool $showingGrantModal = false;

    public ?int $grantDatabaseId = null;

    public ?int $grantUserId = null;

    /** @var list<string> */
    public array $selectedPrivileges = ['ALL PRIVILEGES'];

    // Change Password Modal
    public bool $showingPasswordModal = false;

    public ?int $passwordUserId = null;

    public string $newPassword = '';

    // Drop Confirmations
    public ?int $databaseToDropId = null;

    public ?int $userToDropId = null;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'databases.databaseUsers', 'databaseUsers.databases']);
    }

    public function generateRandomPassword(): void
    {
        // 18 character strong password
        $this->userPassword = Str::password(18, true, true, false, false);
    }

    public function generateNewPassword(): void
    {
        $this->newPassword = Str::password(18, true, true, false, false);
    }

    public function createDatabase(): void
    {
        $this->authorize('view', $this->account);

        $maxAllowed = $this->account->package->max_databases;
        if ($this->account->databases()->count() >= $maxAllowed) {
            $this->addError('dbSuffix', "Package limit reached (Max: {$maxAllowed} databases).");

            return;
        }

        $this->validate([
            'dbSuffix' => ['required', 'string', 'min:1', 'max:40', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $fullName = "{$this->account->linux_username}_{$this->dbSuffix}";

        if (Database::where('name', $fullName)->exists()) {
            $this->addError('dbSuffix', "Database '{$fullName}' already exists.");

            return;
        }

        $database = Database::create([
            'account_id' => $this->account->id,
            'name' => $fullName,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'size_bytes' => 0,
        ]);

        CreateDatabaseJob::dispatch($database);

        $this->reset(['dbSuffix']);
        session()->flash('status', "Database '{$fullName}' creation initiated.");
    }

    public function confirmDropDatabase(int $id): void
    {
        $this->databaseToDropId = $id;
    }

    public function cancelDropDatabase(): void
    {
        $this->databaseToDropId = null;
    }

    public function dropDatabase(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->databaseToDropId) {
            return;
        }

        /** @var Database $db */
        $db = $this->account->databases()->findOrFail($this->databaseToDropId);
        $dbName = $db->name;

        DropDatabaseJob::dispatch($this->account->server, $this->account, $dbName);
        $db->delete();

        $this->databaseToDropId = null;
        session()->flash('status', "Database '{$dbName}' dropped successfully.");
    }

    public function createDatabaseUser(): void
    {
        $this->authorize('view', $this->account);

        $maxAllowed = $this->account->package->max_db_users;
        if ($this->account->databaseUsers()->count() >= $maxAllowed) {
            $this->addError('userSuffix', "Package limit reached (Max: {$maxAllowed} database users).");

            return;
        }

        $this->validate([
            'userSuffix' => ['required', 'string', 'min:1', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'userPassword' => ['required', 'string', 'min:8', 'max:100'],
            'userHost' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.\-%]+$/'],
        ]);

        $fullUsername = "{$this->account->linux_username}_{$this->userSuffix}";

        $exists = DatabaseUser::where('username', $fullUsername)
            ->where('remote_host', $this->userHost)
            ->exists();

        if ($exists) {
            $this->addError('userSuffix', "User '{$fullUsername}'@'{$this->userHost}' already exists.");

            return;
        }

        $dbUser = DatabaseUser::create([
            'account_id' => $this->account->id,
            'username' => $fullUsername,
            'remote_host' => $this->userHost,
            'max_connections' => 20,
        ]);

        CreateDatabaseUserJob::dispatch($dbUser, $this->userPassword);

        $this->generatedPasswordNotice = $this->userPassword;
        $this->reset(['userSuffix', 'userPassword']);
        $this->userHost = 'localhost';

        session()->flash('status', "Database user '{$fullUsername}' created successfully.");
    }

    public function confirmDropUser(int $id): void
    {
        $this->userToDropId = $id;
    }

    public function cancelDropUser(): void
    {
        $this->userToDropId = null;
    }

    public function dropDatabaseUser(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->userToDropId) {
            return;
        }

        /** @var DatabaseUser $dbUser */
        $dbUser = $this->account->databaseUsers()->findOrFail($this->userToDropId);
        $username = $dbUser->username;
        $host = $dbUser->remote_host;

        DropDatabaseUserJob::dispatch($this->account->server, $this->account, $username, $host);
        $dbUser->delete();

        $this->userToDropId = null;
        session()->flash('status', "Database user '{$username}'@'{$host}' dropped.");
    }

    public function openPasswordModal(int $userId): void
    {
        $this->authorize('view', $this->account);
        $this->passwordUserId = $userId;
        $this->newPassword = '';
        $this->showingPasswordModal = true;
    }

    public function closePasswordModal(): void
    {
        $this->showingPasswordModal = false;
        $this->passwordUserId = null;
        $this->newPassword = '';
    }

    public function updatePassword(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'newPassword' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        if (! $this->passwordUserId) {
            return;
        }

        /** @var DatabaseUser $dbUser */
        $dbUser = $this->account->databaseUsers()->findOrFail($this->passwordUserId);

        UpdateDatabaseUserPasswordJob::dispatch($dbUser, $this->newPassword);

        $this->generatedPasswordNotice = $this->newPassword;
        $this->closePasswordModal();
        session()->flash('status', "Password updated for user '{$dbUser->username}'.");
    }

    public function openGrantModal(?int $dbId = null, ?int $userId = null): void
    {
        $this->authorize('view', $this->account);
        $this->grantDatabaseId = $dbId;
        $this->grantUserId = $userId;
        $this->selectedPrivileges = ['ALL PRIVILEGES'];

        // If grant already exists, pre-load existing privileges
        if ($dbId && $userId) {
            $grant = DatabaseUserGrant::where('database_id', $dbId)
                ->where('database_user_id', $userId)
                ->first();
            if ($grant && ! empty($grant->privileges)) {
                $this->selectedPrivileges = $grant->privileges;
            }
        }

        $this->showingGrantModal = true;
    }

    public function closeGrantModal(): void
    {
        $this->showingGrantModal = false;
        $this->grantDatabaseId = null;
        $this->grantUserId = null;
        $this->selectedPrivileges = ['ALL PRIVILEGES'];
    }

    public function saveGrant(): void
    {
        $this->authorize('view', $this->account);

        $this->validate([
            'grantDatabaseId' => ['required', 'integer', Rule::exists('databases', 'id')->where('account_id', $this->account->id)],
            'grantUserId' => ['required', 'integer', Rule::exists('database_users', 'id')->where('account_id', $this->account->id)],
            'selectedPrivileges' => ['required', 'array', 'min:1'],
            'selectedPrivileges.*' => ['string', Rule::in(self::ALLOWED_PRIVILEGES)],
        ]);

        /** @var DatabaseUserGrant $grant */
        $grant = DatabaseUserGrant::updateOrCreate(
            [
                'database_id' => $this->grantDatabaseId,
                'database_user_id' => $this->grantUserId,
            ],
            [
                'privileges' => $this->selectedPrivileges,
            ]
        );

        GrantDatabasePrivilegesJob::dispatch($grant);

        $this->closeGrantModal();
        session()->flash('status', 'Database privileges assigned successfully.');
    }

    public function revokeGrant(int $grantId): void
    {
        $this->authorize('view', $this->account);

        /** @var DatabaseUserGrant $grant */
        $grant = DatabaseUserGrant::with(['database', 'databaseUser'])->findOrFail($grantId);

        // Security check: ensure both database and user belong to this account
        if ($grant->database->account_id !== $this->account->id || $grant->databaseUser->account_id !== $this->account->id) {
            abort(403);
        }

        RevokeDatabasePrivilegesJob::dispatch(
            $this->account->server,
            $this->account,
            $grant->databaseUser->username,
            $grant->database->name,
            $grant->databaseUser->remote_host
        );

        $grant->delete();
        session()->flash('status', "Privileges revoked for '{$grant->databaseUser->username}' on '{$grant->database->name}'.");
    }

    public function exportDatabase(int $databaseId): void
    {
        $this->authorize('view', $this->account);

        /** @var Database $db */
        $db = $this->account->databases()->findOrFail($databaseId);

        ExportDatabaseJob::dispatch($db);

        session()->flash('status', "SQL Export started for '{$db->name}'. File will be saved to /home/{$this->account->linux_username}/backups/.");
    }

    public function refreshUsage(): void
    {
        $this->authorize('view', $this->account);

        SyncDatabaseUsageJob::dispatch($this->account);

        session()->flash('status', 'Database usage synchronization queued.');
    }

    public function render(): View
    {
        $databases = $this->account->databases()
            ->with(['databaseUsers', 'grants.databaseUser'])
            ->get();

        $databaseUsers = $this->account->databaseUsers()
            ->with(['databases', 'grants.database'])
            ->get();

        return view('livewire.databases.database-manager', [
            'databases' => $databases,
            'databaseUsers' => $databaseUsers,
            'allowedPrivileges' => self::ALLOWED_PRIVILEGES,
        ]);
    }
}
