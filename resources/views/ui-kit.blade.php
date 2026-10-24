<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>UI Kit &amp; Component Gallery — {{ config('app.name', 'HPanel') }}</title>

    <!-- Theme Preference Initialization (Prevents FOUC) -->
    <script>
        (function() {
            var theme = localStorage.getItem('theme') || localStorage.getItem('color-theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full font-sans antialiased bg-[var(--bg-app)] text-[var(--text)] transition-colors duration-200 p-3 sm:p-6 lg:p-8" x-data="{ modalOpen: false }">

    <!-- Outer Frame (Reference Dashboard App Shell Style) -->
    <div class="max-w-7xl mx-auto bg-[var(--bg-shell)] border border-[var(--border)] rounded-[28px] p-6 sm:p-8 shadow-xs space-y-10">

        <!-- Top bar with title and Theme Toggle -->
        <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-[var(--border)]">
            <div class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-2xl bg-gradient-primary text-white font-bold flex items-center justify-center text-lg shadow-sm">H</span>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-[var(--text)]">Design Tokens &amp; UI Kit</h1>
                    <p class="text-xs sm:text-sm text-[var(--text-muted)] mt-0.5">Reference preview for tokens, buttons, cards, chips, forms, modals, tables, and feedback components</p>
                </div>
            </div>

            <div class="flex items-center gap-3 self-end sm:self-auto">
                <span class="hidden sm:inline-block px-3 py-1 rounded-full text-xs font-mono bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-500/20">--primary-500</span>
                <button
                    type="button"
                    onclick="window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: 'Interactive toast dispatched successfully!' } }))"
                    class="h-9 px-4 text-xs font-semibold rounded-full border border-[var(--border)] bg-[var(--bg-card)] text-[var(--text)] hover:bg-[var(--bg-shell)] transition cursor-pointer"
                >
                    Test Toast
                </button>
                <x-ui.theme-toggle size="md" />
            </div>
        </header>

        <!-- 1. Buttons & Icon Buttons -->
        <section class="space-y-4">
            <div>
                <h2 class="text-base font-bold text-[var(--text)] uppercase tracking-wider text-xs text-[var(--text-muted)]">1. Pill Buttons &amp; Circular Icon Buttons</h2>
            </div>
            <x-ui.card>
                <div class="space-y-6">
                    <div>
                        <p class="text-xs font-semibold text-[var(--text-muted)] mb-3">Button Variants (9999px Pill Radius)</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.button variant="primary">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                Primary Gradient
                            </x-ui.button>

                            <x-ui.button variant="outline">
                                Outline Pill
                            </x-ui.button>

                            <x-ui.button variant="secondary">
                                Secondary Pill
                            </x-ui.button>

                            <x-ui.button variant="ghost">
                                Ghost Action
                            </x-ui.button>

                            <x-ui.button variant="danger">
                                Destructive Action
                            </x-ui.button>

                            <x-ui.button variant="primary" size="sm">
                                Small Pill
                            </x-ui.button>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-[var(--border)]">
                        <p class="text-xs font-semibold text-[var(--text-muted)] mb-3">Circular Icon Buttons</p>
                        <div class="flex items-center gap-3">
                            <x-ui.icon-button label="Notifications">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            </x-ui.icon-button>

                            <x-ui.icon-button label="Arrow Top Right" variant="primary">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M17 7H7M17 7V17"/></svg>
                            </x-ui.icon-button>

                            <x-ui.icon-button label="Delete" variant="danger">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </x-ui.icon-button>

                            <x-ui.icon-button label="Settings" variant="ghost">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </x-ui.icon-button>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </section>

        <!-- 2. Status Chips & Tabs -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-3">2. Status Chips</h2>
                <x-ui.card>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.chip variant="success" dot>Completed / Active</x-ui.chip>
                        <x-ui.chip variant="info" dot>In Progress</x-ui.chip>
                        <x-ui.chip variant="warning" dot>Pending</x-ui.chip>
                        <x-ui.chip variant="danger" dot>Failed / Suspended</x-ui.chip>
                        <x-ui.chip variant="primary">Brand Chip</x-ui.chip>
                        <x-ui.chip variant="neutral">Default</x-ui.chip>
                    </div>
                </x-ui.card>
            </div>

            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-3">3. Pill Tabs Switcher</h2>
                <x-ui.card>
                    <x-ui.tabs :tabs="['all' => 'All Services', 'active' => 'Active (24)', 'pending' => 'Pending (3)', 'archived' => 'Archived']" active="all" />
                </x-ui.card>
            </div>
        </section>

        <!-- 4. Form Controls -->
        <section class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">4. Form Controls (Pill Inputs &amp; 20px Textarea)</h2>
            <x-ui.card title="Input Fields &amp; Form Components" subtitle="Fully rounded 9999px inputs and select with custom chevrons">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <x-ui.input
                        name="domain"
                        label="Domain Name"
                        placeholder="example.com"
                        hint="RFC-compliant lowercase domain"
                        required
                    />

                    <x-ui.select name="php_version" label="PHP Version" required>
                        <option value="8.3">PHP 8.3 (Recommended)</option>
                        <option value="8.2">PHP 8.2</option>
                        <option value="8.1">PHP 8.1</option>
                    </x-ui.select>

                    <div class="md:col-span-2">
                        <x-ui.textarea
                            name="ssh_key"
                            label="Authorized SSH Key"
                            hint="Paste public key in OpenSSH format"
                            monospace
                            rows="3"
                        >ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG1lZ2FwYW5lbF9hZG1pbl9rZXk= admin@hpanel</x-ui.textarea>
                    </div>
                </div>
            </x-ui.card>
        </section>

        <!-- 5. Cards & Overlays (Modal, Dropdown) -->
        <section class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">5. Overlays (Modal &amp; Dropdown)</h2>
            <x-ui.card>
                <div class="flex flex-wrap items-center gap-4">
                    <!-- Modal Trigger -->
                    <x-ui.button variant="primary" @click="modalOpen = true">
                        Open Modal Dialog
                    </x-ui.button>

                    <!-- Dropdown Component -->
                    <x-ui.dropdown align="left" width="56">
                        <x-slot:trigger>
                            <x-ui.button variant="outline">
                                <span>Options Menu</span>
                                <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </x-ui.button>
                        </x-slot:trigger>
                        <x-slot:content>
                            <div class="py-1 text-xs">
                                <a href="#" class="flex items-center gap-2 px-4 py-2 text-[var(--text)] hover:bg-[var(--bg-shell)] rounded-xl transition">
                                    <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View Details
                                </a>
                                <a href="#" class="flex items-center gap-2 px-4 py-2 text-[var(--text)] hover:bg-[var(--bg-shell)] rounded-xl transition">
                                    <svg class="w-4 h-4 text-[var(--text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                    Export Snapshot
                                </a>
                                <div class="my-1 border-t border-[var(--border)]"></div>
                                <a href="#" class="flex items-center gap-2 px-4 py-2 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 rounded-xl transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    Terminate Account
                                </a>
                            </div>
                        </x-slot:content>
                    </x-ui.dropdown>
                </div>
            </x-ui.card>

            <!-- Modal Panel Instance -->
            <div x-show="modalOpen" style="display: none;">
                <x-ui.modal title="Confirm Server Restart" maxWidth="md">
                    <p class="text-sm text-[var(--text-muted)] leading-relaxed">
                        Are you sure you want to trigger a graceful Nginx restart? All active connections will finish cleanly within 30 seconds.
                    </p>
                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" @click="modalOpen = false">
                            Cancel
                        </x-ui.button>
                        <x-ui.button variant="primary" size="sm" @click="modalOpen = false; window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: 'Nginx reload signal issued!' } }))">
                            Confirm Restart
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            </div>
        </section>

        <!-- 6. Table & Empty State & Skeletons -->
        <section class="space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">6. Data Table, Skeletons &amp; Empty State</h2>

            <x-ui.table :headers="['Domain', 'User', 'PHP', 'SSL Status', 'Actions']">
                <tr class="hover:bg-[var(--bg-shell)]/50 transition">
                    <td class="px-6 py-4 font-semibold text-[var(--text)]">mysite.example.com</td>
                    <td class="px-6 py-4 text-[var(--text-muted)]">cust_site1</td>
                    <td class="px-6 py-4 text-xs font-mono">8.3-fpm</td>
                    <td class="px-6 py-4">
                        <x-ui.chip variant="success" dot size="sm">Active (Let's Encrypt)</x-ui.chip>
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.button variant="outline" size="xs">Manage</x-ui.button>
                    </td>
                </tr>
                <tr class="hover:bg-[var(--bg-shell)]/50 transition">
                    <td class="px-6 py-4 font-semibold text-[var(--text)]">api.example.com</td>
                    <td class="px-6 py-4 text-[var(--text-muted)]">cust_site1</td>
                    <td class="px-6 py-4 text-xs font-mono">8.2-fpm</td>
                    <td class="px-6 py-4">
                        <x-ui.chip variant="warning" dot size="sm">Expiring in 5 days</x-ui.chip>
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.button variant="outline" size="xs">Manage</x-ui.button>
                    </td>
                </tr>
            </x-ui.table>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                <!-- Skeleton Preview -->
                <x-ui.card title="Skeleton Loading State">
                    <div class="space-y-3">
                        <x-ui.skeleton variant="pill" height="h-6" width="w-2/3" />
                        <x-ui.skeleton variant="text" height="h-4" width="w-full" />
                        <x-ui.skeleton variant="text" height="h-4" width="w-4/5" />
                        <div class="flex items-center gap-2 pt-2">
                            <x-ui.skeleton variant="circle" width="w-8" height="h-8" />
                            <x-ui.skeleton variant="pill" height="h-8" width="w-28" />
                        </div>
                    </div>
                </x-ui.card>

                <!-- Empty State Preview -->
                <x-ui.card>
                    <x-ui.empty-state
                        title="No Databases Found"
                        description="You haven't provisioned any MariaDB databases for this account yet."
                    >
                        <x-ui.button variant="primary" size="sm">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            Create Database
                        </x-ui.button>
                    </x-ui.empty-state>
                </x-ui.card>
            </div>
        </section>

    </div>

    <!-- Reusable System Toast Component -->
    <x-ui.toast />

    @livewireScripts
</body>
</html>
