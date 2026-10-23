<div class="space-y-6">
    <!-- Header -->
    <x-layout.page-header
        title="MariaDB Databases &middot; {{ $account->primary_domain }}"
        subtitle="Manage MySQL/MariaDB databases, user credentials, granular access privileges, and phpMyAdmin SSO."
    >
        <x-slot:actions>
            <x-ui.button variant="outline" wire:click="refreshUsage" loading="refreshUsage">
                ↻ Sync Storage Usage
            </x-ui.button>
            <x-ui.button variant="primary" wire:click="openGrantModal()">
                + Grant Privileges
            </x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <div class="-mt-3 pb-2 text-xs text-[var(--text-muted)]">
        <a href="{{ route('accounts.show', $account) }}" class="text-blue-600 dark:text-blue-400 hover:underline">&larr; Back to Account: {{ $account->linux_username }}</a>
    </div>

    <!-- Status Alert -->
    @if (session()->has('status'))
        <x-ui.chip variant="success" size="lg" class="w-full justify-start py-2.5 px-4 rounded-2xl">
            {{ session('status') }}
        </x-ui.chip>
    @endif

    <!-- Generated Password Notice (Shown Once) -->
    @if ($generatedPasswordNotice)
        <x-ui.card class="border-amber-500/30 bg-amber-50/40 dark:bg-amber-950/20">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h4 class="text-sm font-bold text-amber-900 dark:text-amber-200">⚠️ Database Password Generated</h4>
                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">
                        Please copy this password immediately. For security, passwords are encrypted/hashed and will not be displayed again.
                    </p>
                    <div class="mt-3 flex items-center gap-3">
                        <code class="px-3 py-1.5 rounded-full bg-[var(--bg-card)] border border-[var(--border)] font-mono text-xs text-amber-600 dark:text-amber-300 select-all">
                            {{ $generatedPasswordNotice }}
                        </code>
                        <span class="text-xs text-[var(--text-muted)]">Copy to clipboard</span>
                    </div>
                </div>
                <x-ui.button variant="ghost" size="xs" wire:click="$set('generatedPasswordNotice', null)">
                    &times; Dismiss
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <!-- Package Limits Bar -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
        <x-ui.card class="flex items-center justify-between">
            <div>
                <span class="text-xs text-[var(--text-muted)] uppercase tracking-wider font-semibold">Databases Limit</span>
                <div class="text-2xl font-extrabold text-[var(--text)] mt-1 tracking-tight">
                    {{ $databases->count() }} <span class="text-sm font-normal text-[var(--text-muted)]">/ {{ $account->package->max_databases }}</span>
                </div>
            </div>
            <x-ui.chip :variant="$databases->count() >= $account->package->max_databases ? 'danger' : 'success'" size="sm">
                {{ $account->package->max_databases - $databases->count() }} Available
            </x-ui.chip>
        </x-ui.card>

        <x-ui.card class="flex items-center justify-between">
            <div>
                <span class="text-xs text-[var(--text-muted)] uppercase tracking-wider font-semibold">Database Users Limit</span>
                <div class="text-2xl font-extrabold text-[var(--text)] mt-1 tracking-tight">
                    {{ $databaseUsers->count() }} <span class="text-sm font-normal text-[var(--text-muted)]">/ {{ $account->package->max_db_users }}</span>
                </div>
            </div>
            <x-ui.chip :variant="$databaseUsers->count() >= $account->package->max_db_users ? 'danger' : 'success'" size="sm">
                {{ $account->package->max_db_users - $databaseUsers->count() }} Available
            </x-ui.chip>
        </x-ui.card>
    </div>

    <!-- Main 2-Column Section: Databases & DB Users Creation -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Create Database Card -->
        <x-ui.card title="Create New Database" subtitle="Databases are automatically prefixed with your system account username.">
            <form wire:submit.prevent="createDatabase" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-1.5 ml-1">Database Name</label>
                    <div class="flex rounded-full shadow-2xs overflow-hidden border border-[var(--border)] bg-[var(--bg-card)]">
                        <span class="inline-flex items-center px-4 bg-[var(--bg-shell)] text-[var(--text-muted)] text-xs font-mono select-none border-r border-[var(--border)]">
                            {{ $account->linux_username }}_
                        </span>
                        <input type="text" wire:model="dbSuffix" placeholder="app"
                            class="flex-1 min-w-0 block w-full px-4 py-2 bg-transparent text-[var(--text)] placeholder-[var(--text-muted)] text-xs font-mono focus:outline-none" />
                    </div>
                    @error('dbSuffix') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                </div>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" loading="createDatabase">
                        Create Database
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <!-- Create Database User Card -->
        <x-ui.card title="Create Database User" subtitle="Users are prefixed with account name and granted per-database access.">
            <form wire:submit.prevent="createDatabaseUser" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-1.5 ml-1">Username</label>
                        <div class="flex rounded-full shadow-2xs overflow-hidden border border-[var(--border)] bg-[var(--bg-card)]">
                            <span class="inline-flex items-center px-3 bg-[var(--bg-shell)] text-[var(--text-muted)] text-xs font-mono select-none border-r border-[var(--border)]">
                                {{ $account->linux_username }}_
                            </span>
                            <input type="text" wire:model="userSuffix" placeholder="usr"
                                class="flex-1 min-w-0 block w-full px-3 py-2 bg-transparent text-[var(--text)] placeholder-[var(--text-muted)] text-xs font-mono focus:outline-none" />
                        </div>
                        @error('userSuffix') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.input name="userHost" label="Host Allow-list" wire:model="userHost" placeholder="localhost" />
                        @error('userHost') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1 ml-1">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Password</label>
                        <button type="button" wire:click="generateRandomPassword" class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                            Generate Strong Password
                        </button>
                    </div>
                    <x-ui.input name="userPassword" type="text" wire:model="userPassword" placeholder="Min 8 characters" />
                    @error('userPassword') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                </div>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" loading="createDatabaseUser">
                        Create Database User
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    <!-- Databases Table -->
    <div>
        <div class="flex items-center justify-between mb-3 px-1">
            <h3 class="text-base font-bold text-[var(--text)] tracking-tight">Current Databases</h3>
            <span class="text-xs text-[var(--text-muted)]">{{ $databases->count() }} database(s)</span>
        </div>

        <x-ui.table :headers="['Database Name', 'Size', 'Privileged Users', 'Actions']">
            @forelse ($databases as $db)
                <tr class="hover:bg-[var(--bg-shell)]/60 transition-colors">
                    <td class="px-6 py-4 font-mono text-xs font-bold text-[var(--text)]">
                        {{ $db->name }}
                    </td>
                    <td class="px-6 py-4 text-xs font-mono text-[var(--text-muted)]">
                        {{ $db->formattedSize() }}
                    </td>
                    <td class="px-6 py-4 text-xs">
                        @if ($db->grants->isEmpty())
                            <span class="text-[var(--text-muted)] italic">No users assigned</span>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($db->grants as $grant)
                                    <x-ui.chip variant="neutral" size="sm" class="font-mono">
                                        {{ $grant->databaseUser->username }}
                                        <button type="button" wire:click="revokeGrant({{ $grant->id }})" title="Revoke Access" class="text-[var(--text-muted)] hover:text-rose-500 font-bold ml-1 cursor-pointer">&times;</button>
                                    </x-ui.chip>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                        <div class="flex items-center justify-end gap-1.5">
                            <x-ui.button variant="outline" size="xs" wire:click="openGrantModal({{ $db->id }}, null)">
                                + Add User
                            </x-ui.button>
                            <x-ui.button variant="outline" size="xs" wire:click="exportDatabase({{ $db->id }})">
                                Export SQL
                            </x-ui.button>
                            <x-ui.button variant="danger" size="xs" wire:click="confirmDropDatabase({{ $db->id }})">
                                Drop
                            </x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center">
                        <x-ui.empty-state
                            title="No MariaDB databases created yet"
                            description="Use the form above to provision your first database."
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>

    <!-- Database Users Table -->
    <div>
        <div class="flex items-center justify-between mb-3 px-1">
            <h3 class="text-base font-bold text-[var(--text)] tracking-tight">Current Database Users</h3>
            <span class="text-xs text-[var(--text-muted)]">{{ $databaseUsers->count() }} user(s)</span>
        </div>

        <x-ui.table :headers="['Username', 'Allowed Host', 'Associated Databases', 'Actions']">
            @forelse ($databaseUsers as $user)
                <tr class="hover:bg-[var(--bg-shell)]/60 transition-colors">
                    <td class="px-6 py-4 font-mono text-xs font-bold text-[var(--text)]">
                        {{ $user->username }}
                    </td>
                    <td class="px-6 py-4 font-mono text-xs text-[var(--text-muted)]">
                        {{ $user->remote_host }}
                    </td>
                    <td class="px-6 py-4 text-xs">
                        @if ($user->databases->isEmpty())
                            <span class="text-[var(--text-muted)] italic">None</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->databases as $db)
                                    <x-ui.chip variant="neutral" size="sm" class="font-mono">
                                        {{ $db->name }}
                                    </x-ui.chip>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                        <div class="flex items-center justify-end gap-1.5">
                            <a href="{{ route('accounts.databases.sso', ['account' => $account, 'databaseUser' => $user]) }}"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full border border-amber-300 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 font-semibold text-xs hover:opacity-90 transition">
                                <span>🔑</span> phpMyAdmin SSO
                            </a>
                            <x-ui.button variant="outline" size="xs" wire:click="openPasswordModal({{ $user->id }})">
                                Change Password
                            </x-ui.button>
                            <x-ui.button variant="danger" size="xs" wire:click="confirmDropUser({{ $user->id }})">
                                Delete
                            </x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center">
                        <x-ui.empty-state
                            title="No database users created yet"
                            description="Use the form above to add a database user."
                        />
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>

    <!-- Grant Privileges Modal -->
    @if ($showingGrantModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="closeGrantModal"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:max-w-xl w-full z-10">
                    <form wire:submit.prevent="saveGrant">
                        <div class="px-6 py-4.5 border-b border-[var(--border)] flex justify-between items-center">
                            <h3 class="text-base font-bold text-[var(--text)] tracking-tight">Manage Database Privileges</h3>
                            <x-ui.icon-button label="Close" variant="ghost" size="sm" wire:click="closeGrantModal">
                                &times;
                            </x-ui.icon-button>
                        </div>

                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <x-ui.select name="grantDatabaseId" label="Target Database" wire:model="grantDatabaseId">
                                    <option value="">-- Select Database --</option>
                                    @foreach ($databases as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select name="grantUserId" label="Database User" wire:model="grantUserId">
                                    <option value="">-- Select User --</option>
                                    @foreach ($databaseUsers as $u)
                                        <option value="{{ $u->id }}">{{ $u->username }} ({{ $u->remote_host }})</option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2 ml-1">Privileges</label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 bg-[var(--bg-shell)] p-4 rounded-[20px] border border-[var(--border)]">
                                    @foreach ($allowedPrivileges as $priv)
                                        <label class="inline-flex items-center gap-2 text-xs text-[var(--text)] hover:text-blue-600 cursor-pointer">
                                            <input type="checkbox" wire:model="selectedPrivileges" value="{{ $priv }}"
                                                class="rounded border-[var(--border)] text-blue-600 focus:ring-blue-500">
                                            <span class="font-mono text-[11px]">{{ $priv }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('selectedPrivileges') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-end gap-3">
                            <x-ui.button variant="outline" size="sm" wire:click="closeGrantModal">
                                Cancel
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm" loading="saveGrant">
                                Apply Privileges
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Change Password Modal -->
    @if ($showingPasswordModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="closePasswordModal"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:max-w-md w-full z-10">
                    <form wire:submit.prevent="updatePassword">
                        <div class="px-6 py-4.5 border-b border-[var(--border)] flex justify-between items-center">
                            <h3 class="text-base font-bold text-[var(--text)] tracking-tight">Change User Password</h3>
                            <x-ui.icon-button label="Close" variant="ghost" size="sm" wire:click="closePasswordModal">
                                &times;
                            </x-ui.icon-button>
                        </div>

                        <div class="p-6 space-y-4">
                            <div>
                                <div class="flex items-center justify-between mb-1 ml-1">
                                    <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">New Password</label>
                                    <button type="button" wire:click="generateNewPassword" class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        Generate
                                    </button>
                                </div>
                                <x-ui.input name="newPassword" type="text" wire:model="newPassword" placeholder="Min 8 characters" />
                                @error('newPassword') <span class="text-rose-500 text-xs mt-1 block ml-2">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="px-6 py-4 bg-[var(--bg-shell)]/60 border-t border-[var(--border)] flex justify-end gap-3">
                            <x-ui.button variant="outline" size="sm" wire:click="closePasswordModal">
                                Cancel
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm" loading="updatePassword">
                                Update Password
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Drop Database Confirmation Modal -->
    @if ($databaseToDropId)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="cancelDropDatabase"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:max-w-md w-full p-6 z-10">
                    <h3 class="text-base font-bold text-[var(--text)] tracking-tight mb-2">Drop Database?</h3>
                    <p class="text-xs text-rose-600 dark:text-rose-400 mb-6 leading-relaxed">
                        Are you sure you want to drop this database? All tables, stored data, and indexes will be permanently deleted. This action cannot be undone.
                    </p>
                    <div class="flex justify-end gap-3">
                        <x-ui.button variant="outline" size="sm" wire:click="cancelDropDatabase">
                            Cancel
                        </x-ui.button>
                        <x-ui.button variant="danger" size="sm" wire:click="dropDatabase" loading="dropDatabase">
                            Confirm Drop
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Drop User Confirmation Modal -->
    @if ($userToDropId)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="cancelDropUser"></div>

                <div class="relative bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-2xl transform transition-all sm:max-w-md w-full p-6 z-10">
                    <h3 class="text-base font-bold text-[var(--text)] tracking-tight mb-2">Drop Database User?</h3>
                    <p class="text-xs text-rose-600 dark:text-rose-400 mb-6 leading-relaxed">
                        Are you sure you want to drop this database user? Applications connecting with this user credentials will no longer be able to authenticate.
                    </p>
                    <div class="flex justify-end gap-3">
                        <x-ui.button variant="outline" size="sm" wire:click="cancelDropUser">
                            Cancel
                        </x-ui.button>
                        <x-ui.button variant="danger" size="sm" wire:click="dropDatabaseUser" loading="dropDatabaseUser">
                            Confirm Drop User
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
