<div>
    <x-layout.page-header 
        title="FTP / SFTP Accounts · {{ $account->primary_domain }}" 
        subtitle="Manage secondary SFTP sub-accounts and configure primary SFTP credentials."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                    + Add SFTP Account
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Accounts Table Column -->
        <div class="lg:col-span-2">
            <x-ui.card class="p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-border flex justify-between items-center">
                    <h3 class="text-sm font-semibold text-main">Active Sub-Accounts</h3>
                    <span class="text-xs text-muted">Limit: {{ $accounts->count() }} / {{ $account->package->max_ftp_accounts ?? 5 }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                                <th class="py-3 px-6">Username</th>
                                <th class="py-3 px-6">Restricted Directory</th>
                                <th class="py-3 px-6">Status</th>
                                <th class="py-3 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-main">
                            @forelse ($accounts as $item)
                                <tr class="hover:bg-shell/40 transition-colors">
                                    <td class="px-6 py-3 font-mono text-xs font-semibold text-main">
                                        {{ $item->username }}
                                    </td>
                                    <td class="px-6 py-3 font-mono text-xs text-muted truncate max-w-xs">
                                        {{ $item->homedir }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <button wire:click="toggleStatus({{ $item->id }})" class="cursor-pointer">
                                            <x-ui.chip :variant="$item->is_active ? 'success' : 'warning'" size="sm">
                                                {{ $item->is_active ? 'Active' : 'Disabled' }}
                                            </x-ui.chip>
                                        </button>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-xs">
                                        <button wire:click="deleteFtpAccount({{ $item->id }})" wire:confirm="Remove this SFTP account?"
                                            class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-muted text-xs">
                                        No secondary SFTP accounts created yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <!-- Connection Details Guide -->
        <div class="lg:col-span-1 space-y-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-main mb-3">SFTP Connection Settings</h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-muted block mb-0.5">Protocol</span>
                        <span class="font-semibold text-main">SFTP (SSH File Transfer Protocol)</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Host</span>
                        @php
                            $publicHost = !empty($account->server->public_ip) 
                                ? trim(explode(',', $account->server->public_ip)[0]) 
                                : $account->server->hostname;
                            if ($publicHost === '127.0.0.1' || $publicHost === 'localhost' || empty($publicHost)) {
                                $publicHost = $account->server->hostname;
                            }
                        @endphp
                        <span class="font-mono text-primary-500 font-semibold">{{ $publicHost }}</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Port</span>
                        <span class="font-mono text-main">22</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Primary Username</span>
                        <span class="font-mono text-main">{{ $account->linux_username }}</span>
                    </div>
                    <div>
                        <span class="text-muted block mb-0.5">Client Support</span>
                        <span class="text-muted">FileZilla, Cyberduck, WinSCP, Transmit, VS Code SFTP</span>
                    </div>
                </div>

                <div class="mt-5 pt-4 border-t border-border space-y-2">
                    <x-ui.button wire:click="openPrimaryPasswordModal" variant="primary" size="sm" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        Set SFTP Password
                    </x-ui.button>
                    <x-ui.button wire:click="runDiagnostic" variant="outline" size="sm" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-1.5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Test SFTP Login
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-1">Create SFTP Sub-Account</h3>
                <p class="text-xs text-muted mb-4">Grant restricted file access to a contractor or client without exposing your entire home folder.</p>

                <form wire:submit.prevent="createFtpAccount" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Username</label>
                        <div class="flex rounded-full border border-border bg-card overflow-hidden shadow-xs focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/20">
                            <span class="inline-flex items-center px-3.5 bg-shell text-muted font-mono text-xs border-r border-border">
                                {{ $account->linux_username }}_
                            </span>
                            <input type="text" wire:model="subuser" placeholder="developer"
                                class="flex-1 min-w-0 block w-full px-3.5 py-2 bg-transparent text-main text-xs font-mono outline-hidden">
                        </div>
                        @error('subuser') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Restricted Directory (Homedir)</label>
                        <x-ui.input type="text" wire:model="homedir" class="font-mono" />
                        @error('homedir') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-muted mt-1">Must reside strictly inside <span class="font-mono text-main">/home/{{ $account->linux_username }}/</span>.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Password</label>
                        <x-ui.input type="password" wire:model="password" />
                        @error('password') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Confirm Password</label>
                        <x-ui.input type="password" wire:model="password_confirmation" />
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('isCreating', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Create Account
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Set Primary SFTP Password Modal -->
    @if ($isSettingPrimaryPassword)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-1">Set Primary SFTP Password</h3>
                <p class="text-xs text-muted mb-4">
                    Set a secure password for Linux user <span class="font-mono font-semibold text-main">{{ $account->linux_username }}</span>. 
                    This enables SFTP access on the server.
                </p>

                <form wire:submit.prevent="savePrimaryPassword" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">SFTP Password</label>
                        <x-ui.input type="text" wire:model="primaryPassword" class="font-mono text-xs" />
                        @error('primaryPassword') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-muted mt-1.5">Minimum 12 characters. Pre-generated with strong random characters.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-border">
                        <x-ui.button type="button" wire:click="closePrimaryPasswordModal" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="savePrimaryPassword">Save Password</span>
                            <span wire:loading wire:target="savePrimaryPassword">Applying...</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Show Generated Password Modal -->
    @if ($showPasswordModal && $generatedPassword)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <div class="w-10 h-10 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-main mb-1">SFTP Password Updated</h3>
                <p class="text-xs text-muted mb-4">
                    SFTP login for <span class="font-mono font-semibold text-main">{{ $account->linux_username }}</span> has been configured. 
                    Copy this password now — it is not stored in plaintext and will not be displayed again.
                </p>

                <div x-data="{ copied: false }" class="p-3.5 bg-shell border border-border rounded-[16px] mb-5">
                    <div class="flex items-center justify-between text-xs mb-1.5">
                        <span class="text-muted font-medium">New Password</span>
                        <button type="button" 
                            @click="navigator.clipboard.writeText('{{ $generatedPassword }}'); copied = true; setTimeout(() => copied = false, 2000)" 
                            class="text-xs text-primary-500 hover:text-primary-600 font-medium cursor-pointer">
                            <span x-show="!copied">Copy to Clipboard</span>
                            <span x-show="copied" class="text-emerald-500 font-semibold">✓ Copied!</span>
                        </button>
                    </div>
                    <div class="font-mono text-xs text-main select-all break-all p-2 bg-card rounded-md border border-border/50">
                        {{ $generatedPassword }}
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <x-ui.button type="button" wire:click="closeShowPasswordModal" variant="primary" size="sm">
                        I Have Saved the Password
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- SFTP Diagnostic Modal -->
    @if ($showDiagnosticModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-main">SFTP Configuration Diagnostic</h3>
                    <button type="button" wire:click="closeDiagnosticModal" class="text-muted hover:text-main text-lg">&times;</button>
                </div>
                <p class="text-xs text-muted mb-4">
                    Effective SSH daemon settings for user <span class="font-mono font-semibold text-main">{{ $account->linux_username }}</span> on server <span class="font-mono">{{ $account->server->name }}</span>.
                </p>

                @if ($diagnosticError)
                    <div class="p-3.5 rounded-[14px] bg-rose-500/10 border border-rose-500/20 text-xs text-rose-500 dark:text-rose-400 mb-4">
                        {{ $diagnosticError }}
                    </div>
                @endif

                @if ($sftpDiagnostic)
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div class="p-3 bg-shell rounded-[14px] border border-border">
                                <span class="text-muted block text-[11px] mb-1">Linux User Exists</span>
                                <x-ui.chip :variant="!empty($sftpDiagnostic['user_exists']) ? 'success' : 'danger'" size="sm">
                                    {{ !empty($sftpDiagnostic['user_exists']) ? 'Yes (UID exists)' : 'No' }}
                                </x-ui.chip>
                            </div>
                            <div class="p-3 bg-shell rounded-[14px] border border-border">
                                <span class="text-muted block text-[11px] mb-1">Password Authentication</span>
                                <x-ui.chip :variant="strtolower($sftpDiagnostic['password_authentication'] ?? '') === 'yes' ? 'success' : 'danger'" size="sm">
                                    {{ $sftpDiagnostic['password_authentication'] ?? 'unknown' }}
                                </x-ui.chip>
                            </div>
                            <div class="p-3 bg-shell rounded-[14px] border border-border col-span-2">
                                <span class="text-muted block text-[11px] mb-1">ForceCommand</span>
                                <span class="font-mono text-xs font-semibold {{ str_contains($sftpDiagnostic['force_command'] ?? '', 'internal-sftp') ? 'text-emerald-500' : 'text-amber-500' }}">
                                    {{ $sftpDiagnostic['force_command'] ?: '(none)' }}
                                </span>
                            </div>
                            <div class="p-3 bg-shell rounded-[14px] border border-border col-span-2">
                                <span class="text-muted block text-[11px] mb-1">ChrootDirectory</span>
                                <span class="font-mono text-xs {{ empty($sftpDiagnostic['chroot_directory']) || $sftpDiagnostic['chroot_directory'] === 'none' ? 'text-emerald-500' : 'text-amber-500' }}">
                                    {{ empty($sftpDiagnostic['chroot_directory']) || $sftpDiagnostic['chroot_directory'] === 'none' ? 'none (Root-ownership conflict avoided)' : $sftpDiagnostic['chroot_directory'] }}
                                </span>
                            </div>
                        </div>

                        @if (!empty($sftpDiagnostic['effective_output']))
                            <div>
                                <span class="text-[11px] font-medium text-muted block mb-1">Raw Match Output</span>
                                <pre class="p-3 bg-shell text-main font-mono text-[11px] rounded-[14px] border border-border overflow-x-auto max-h-36">{{ $sftpDiagnostic['effective_output'] }}</pre>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex justify-end pt-4 mt-4 border-t border-border">
                    <x-ui.button type="button" wire:click="closeDiagnosticModal" variant="outline" size="sm">
                        Close
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
