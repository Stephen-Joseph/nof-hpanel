<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Cron Jobs &middot; {{ $account->primary_domain }}" 
        subtitle="Configure automated background tasks, recurring scripts, and scheduled maintenance commands."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                <span class="text-xs font-mono px-3 py-1.5 rounded-full border border-border bg-card text-muted">
                    Limit: <strong class="text-main">{{ $jobs->count() }}</strong> / {{ $account->package->max_cron_jobs }}
                </span>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Status Alert -->
    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Form Column (1/3) -->
        <div class="lg:col-span-1">
            <x-ui.card class="sticky top-6">
                <h3 class="text-base font-semibold text-main mb-1">
                    {{ $isEditing ? 'Edit Cron Job' : 'Add New Cron Job' }}
                </h3>
                <p class="text-xs text-muted mb-4">Commands execute as user <strong class="text-main font-mono">{{ $account->linux_username }}</strong> under account cgroup limits.</p>

                <form wire:submit.prevent="saveJob" class="space-y-4">
                    <!-- Job Name -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Job Description / Name</label>
                        <x-ui.input type="text" wire:model="name" placeholder="e.g. Laravel Scheduler or Cache Cleanup" />
                        @error('name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Preset Selector -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Common Schedule Presets</label>
                        <x-ui.select wire:model.live="preset">
                            <option value="every_minute">Every minute (* * * * *)</option>
                            <option value="every_5_minutes">Every 5 minutes (*/5 * * * *)</option>
                            <option value="every_15_minutes">Every 15 minutes (*/15 * * * *)</option>
                            <option value="every_30_minutes">Every 30 minutes (*/30 * * * *)</option>
                            <option value="hourly">Once an hour (0 * * * *)</option>
                            <option value="daily_midnight">Daily at midnight (0 0 * * *)</option>
                            <option value="daily_2am">Daily at 2:00 AM (0 2 * * *)</option>
                            <option value="weekly_sunday">Weekly on Sunday (0 0 * * 0)</option>
                            <option value="monthly_first">Monthly on 1st (0 0 1 * *)</option>
                            <option value="custom">Custom Schedule</option>
                        </x-ui.select>
                    </div>

                    <!-- Granular Cron Pickers -->
                    <div class="grid grid-cols-5 gap-1.5 bg-shell p-3 rounded-[16px] border border-border text-center">
                        <div>
                            <label class="block text-[10px] text-muted uppercase font-semibold">Min</label>
                            <input type="text" wire:model.live.debounce.300ms="minute" placeholder="*"
                                class="mt-1 w-full text-center px-1 py-1 rounded-full bg-card border border-border text-main text-xs font-mono focus:border-primary-500 outline-hidden" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-muted uppercase font-semibold">Hour</label>
                            <input type="text" wire:model.live.debounce.300ms="hour" placeholder="*"
                                class="mt-1 w-full text-center px-1 py-1 rounded-full bg-card border border-border text-main text-xs font-mono focus:border-primary-500 outline-hidden" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-muted uppercase font-semibold">Day</label>
                            <input type="text" wire:model.live.debounce.300ms="dayOfMonth" placeholder="*"
                                class="mt-1 w-full text-center px-1 py-1 rounded-full bg-card border border-border text-main text-xs font-mono focus:border-primary-500 outline-hidden" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-muted uppercase font-semibold">Month</label>
                            <input type="text" wire:model.live.debounce.300ms="month" placeholder="*"
                                class="mt-1 w-full text-center px-1 py-1 rounded-full bg-card border border-border text-main text-xs font-mono focus:border-primary-500 outline-hidden" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-muted uppercase font-semibold">Weekday</label>
                            <input type="text" wire:model.live.debounce.300ms="dayOfWeek" placeholder="*"
                                class="mt-1 w-full text-center px-1 py-1 rounded-full bg-card border border-border text-main text-xs font-mono focus:border-primary-500 outline-hidden" />
                        </div>
                    </div>
                    <div class="text-[11px] text-muted font-mono flex items-center justify-between">
                        <span>Expression: <strong class="text-primary-500">{{ $schedule }}</strong></span>
                    </div>
                    @error('schedule') <span class="text-rose-500 text-xs block">{{ $message }}</span> @enderror

                    <!-- Command -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Command to Execute</label>
                        <x-ui.textarea wire:model="command" rows="3" placeholder="/usr/bin/php /home/{{ $account->linux_username }}/public_html/artisan schedule:run" class="font-mono text-xs" />
                        @error('command') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Email Output (Optional) -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Send Output Email (Optional)</label>
                        <x-ui.input type="email" wire:model="emailOutput" placeholder="admin@example.com (leave blank for none)" />
                        @error('emailOutput') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2 flex items-center gap-2">
                        <x-ui.button type="submit" variant="primary" class="flex-1">
                            {{ $isEditing ? 'Update Cron Job' : 'Save Cron Job' }}
                        </x-ui.button>
                        @if ($isEditing)
                            <x-ui.button type="button" wire:click="cancelEdit" variant="ghost" size="sm">
                                Cancel
                            </x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        </div>

        <!-- Table Column (2/3) -->
        <div class="lg:col-span-2">
            <x-ui.card class="p-0 overflow-hidden">
                <div class="px-6 py-4 border-b border-border flex justify-between items-center">
                    <h3 class="text-sm font-semibold text-main">Active Scheduled Jobs</h3>
                    <span class="text-xs text-muted">{{ $jobs->count() }} configured job(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                                <th class="py-3 px-6">Job / Schedule</th>
                                <th class="py-3 px-6">Command</th>
                                <th class="py-3 px-6">Status</th>
                                <th class="py-3 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border text-main">
                            @forelse ($jobs as $job)
                                <tr class="hover:bg-shell/40 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-main text-xs">{{ $job->name }}</div>
                                        <div class="text-[11px] text-muted mt-0.5">{{ $job->humanSchedule() }}</div>
                                        <code class="inline-block mt-1 text-[11px] text-primary-500 bg-shell px-2 py-0.5 rounded-full font-mono border border-border">
                                            {{ $job->schedule }}
                                        </code>
                                    </td>
                                    <td class="px-6 py-4 font-mono text-xs text-muted max-w-xs break-all">
                                        {{ $job->command }}
                                        @if ($job->email_output)
                                            <div class="text-[11px] text-muted/70 mt-1 font-sans">
                                                ✉️ Output &rarr; {{ $job->email_output }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <button type="button" wire:click="toggleJob({{ $job->id }})" class="cursor-pointer">
                                            <x-ui.chip :variant="$job->is_enabled ? 'success' : 'warning'" size="sm">
                                                {{ $job->is_enabled ? 'Enabled' : 'Disabled' }}
                                            </x-ui.chip>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-xs space-x-3">
                                        <button type="button" wire:click="viewOutputLog({{ $job->id }})"
                                            class="text-primary-500 hover:text-primary-600 font-medium transition cursor-pointer">
                                            Log
                                        </button>
                                        <button type="button" wire:click="editJob({{ $job->id }})"
                                            class="text-muted hover:text-main font-medium transition cursor-pointer">
                                            Edit
                                        </button>
                                        <button type="button" wire:click="confirmDelete({{ $job->id }})"
                                            class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-muted text-xs">
                                        No scheduled cron jobs configured yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>

    <!-- Output Log Modal -->
    @if ($showingLogModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-2xl w-full p-6 shadow-2xl">
                <div class="flex justify-between items-center pb-4 border-b border-border mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-main">Execution Log &middot; {{ $viewingLogName }}</h3>
                        <p class="text-xs text-muted mt-0.5 font-mono">cron_{{ $viewingLogJobId }}.log (Last 100 lines)</p>
                    </div>
                    <button type="button" wire:click="closeLogModal" class="text-muted hover:text-main text-lg cursor-pointer">&times;</button>
                </div>

                <div class="p-4 bg-shell rounded-[16px] border border-border font-mono text-xs text-main overflow-y-auto max-h-96 space-y-1">
                    @forelse ($viewingLogLines as $line)
                        <div class="whitespace-pre-wrap">{{ $line }}</div>
                    @empty
                        <div class="text-muted italic py-4 text-center">No execution log recorded yet for this job.</div>
                    @endforelse
                </div>

                <div class="pt-4 border-t border-border flex justify-end mt-4">
                    <x-ui.button type="button" wire:click="closeLogModal" variant="outline" size="sm">
                        Close
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($jobToDeleteId)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-bold text-main mb-2">Delete Cron Job?</h3>
                <p class="text-xs text-rose-500 mb-6">
                    Are you sure you want to delete this scheduled job? It will be removed from crontab and will no longer run automatically.
                </p>
                <div class="flex justify-end gap-3">
                    <x-ui.button type="button" wire:click="cancelDelete" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="deleteJob" variant="danger" size="sm">
                        Confirm Delete
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
