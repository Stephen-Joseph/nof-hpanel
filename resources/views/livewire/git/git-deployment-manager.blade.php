<div>
    <!-- Header -->
    <x-layout.page-header 
        title="Git Deployments &middot; {{ $account->primary_domain }}" 
        subtitle="Deploy web applications directly from Git repositories with atomic symlink releases and automatic rollbacks."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Account
                </x-ui.button>
                @if($account->canUseGit())
                    <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Git Repository
                    </x-ui.button>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400 text-xs font-medium">
                        Git Deployment Not Enabled on Package
                    </span>
                @endif
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Feedback messages -->
    @if (session()->has('success'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Deployments List -->
    <div class="space-y-6">
        @forelse ($account->gitDeployments as $deployment)
            <x-ui.card>
                <div class="sm:flex sm:items-start sm:justify-between border-b border-border pb-4 mb-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-base font-bold text-main">{{ $deployment->name }}</h3>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-mono font-medium bg-shell text-primary-500 border border-border">
                                {{ $deployment->branch }}
                            </span>
                            @if($deployment->auto_deploy)
                                <x-ui.chip variant="success" size="sm">
                                    Auto-Deploy Active
                                </x-ui.chip>
                            @endif
                        </div>
                        <p class="text-xs font-mono text-muted mt-1.5 flex flex-wrap items-center gap-2">
                            <span>Repo: <strong class="text-main">{{ $deployment->repository_url }}</strong></span>
                            <span>&bull;</span>
                            <span>Path: <strong class="text-main">{{ $deployment->deploy_path }}</strong></span>
                        </p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex items-center gap-2">
                        <x-ui.button wire:click="showDeployKey({{ $deployment->id }})" variant="outline" size="sm">
                            Deploy Key &amp; Webhook
                        </x-ui.button>
                        <x-ui.button wire:click="deployNow({{ $deployment->id }})" variant="primary" size="sm">
                            Deploy Now
                        </x-ui.button>
                        <button wire:click="deleteDeployment({{ $deployment->id }})" wire:confirm="Are you sure you want to remove this Git deployment configuration?" class="w-8 h-8 rounded-full border border-border flex items-center justify-center text-muted hover:text-rose-500 hover:border-rose-500/30 transition cursor-pointer">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Build steps badge -->
                @if(!empty($deployment->build_steps))
                    <div class="flex flex-wrap items-center gap-2 mb-4 text-xs text-muted">
                        <span>Build Steps:</span>
                        @foreach($deployment->build_steps as $step)
                            <span class="px-2.5 py-0.5 rounded-full bg-shell border border-border text-main font-mono text-[11px]">
                                {{ $step }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <!-- Releases Table -->
                <div>
                    <h4 class="text-xs font-semibold text-muted uppercase tracking-wider mb-2.5">Recent Releases</h4>
                    <div class="overflow-x-auto rounded-[16px] border border-border bg-shell/50">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-border bg-shell text-muted font-medium">
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">Release Tag</th>
                                    <th class="px-4 py-2.5 font-medium">Commit</th>
                                    <th class="px-4 py-2.5 font-medium">Committer</th>
                                    <th class="px-4 py-2.5 font-medium">Status</th>
                                    <th class="px-4 py-2.5 font-medium">Duration</th>
                                    <th class="px-4 py-2.5 font-medium">Deployed At</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border font-mono text-main">
                                @forelse ($deployment->releases->take(5) as $release)
                                    <tr class="hover:bg-shell/80 transition">
                                        <td class="px-4 py-2.5 font-semibold text-main">{{ $release->release_tag }}</td>
                                        <td class="px-4 py-2.5">
                                            @if($release->commit_hash)
                                                <span class="text-primary-500" title="{{ $release->commit_message }}">{{ substr($release->commit_hash, 0, 7) }}</span>
                                                <span class="text-muted truncate max-w-xs block font-sans text-[11px]">{{ $release->commit_message }}</span>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-muted font-sans">{{ $release->committer ?? '-' }}</td>
                                        <td class="px-4 py-2.5">
                                            @if($release->status === 'success')
                                                <x-ui.chip variant="success" size="sm">Success</x-ui.chip>
                                            @elseif($release->status === 'deploying')
                                                <x-ui.chip variant="info" size="sm" class="animate-pulse">Deploying...</x-ui.chip>
                                            @else
                                                <x-ui.chip variant="danger" size="sm">Failed</x-ui.chip>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-muted">{{ $release->duration_seconds > 0 ? $release->duration_seconds . 's' : '-' }}</td>
                                        <td class="px-4 py-2.5 text-muted font-sans">{{ $release->created_at?->diffForHumans() }}</td>
                                        <td class="px-4 py-2.5 text-right font-sans space-x-2">
                                            <button wire:click="viewLogs({{ $release->id }})" class="text-primary-500 hover:text-primary-600 text-xs font-medium cursor-pointer">Logs</button>
                                            @if($release->status === 'success' && $loop->iteration > 1)
                                                <button wire:click="rollback({{ $deployment->id }}, '{{ $release->release_tag }}')" wire:confirm="Roll back to release {{ $release->release_tag }}?" class="text-amber-500 hover:text-amber-600 text-xs font-medium cursor-pointer">Rollback</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-6 text-center text-muted font-sans">No releases deployed yet. Click "Deploy Now" or push to your repository.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-950/40 text-primary-500 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-main">No Git Deployments Configured</h3>
                <p class="mt-1 text-xs text-muted max-w-md mx-auto">Connect a GitHub, GitLab, or self-hosted Git repository to enable automated releases.</p>
                @if($account->canUseGit())
                    <div class="mt-5">
                        <x-ui.button wire:click="openCreateModal" variant="primary" size="sm">
                            Connect First Repository
                        </x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @endforelse
    </div>

    <!-- Create Deployment Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Connect Git Repository</h3>
                <p class="text-xs text-muted mb-4">Deploy into account directory using SSH authentication and atomic symlinks.</p>

                <form wire:submit.prevent="createDeployment" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Deployment Name</label>
                        <x-ui.input type="text" wire:model="name" placeholder="e.g. Production Web App" />
                        @error('name') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Target Domain (Optional)</label>
                        <x-ui.select wire:model.live="domain_id">
                            <option value="">Custom Directory</option>
                            @foreach ($account->domains as $dom)
                                <option value="{{ $dom->id }}">{{ $dom->domain }} ({{ $dom->document_root }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Repository URL (SSH or HTTPS)</label>
                        <x-ui.input type="text" wire:model="repository_url" placeholder="git@github.com:username/repository.git" class="font-mono" />
                        @error('repository_url') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Branch</label>
                            <x-ui.input type="text" wire:model="branch" placeholder="main" class="font-mono" />
                            @error('branch') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted mb-1.5">Deploy Path</label>
                            <x-ui.input type="text" wire:model="deploy_path" class="font-mono" />
                            @error('deploy_path') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Build Steps -->
                    <div>
                        <label class="block text-xs font-medium text-muted mb-2">Build Steps (Executed as unprivileged user)</label>
                        <div class="space-y-2 bg-shell p-3 rounded-[16px] border border-border">
                            <label class="flex items-center gap-2 text-xs text-main cursor-pointer">
                                <input type="checkbox" wire:model="build_steps" value="composer_install" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                                <span><strong class="font-mono text-main">composer install</strong> (--no-dev --optimize-autoloader)</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs text-main cursor-pointer">
                                <input type="checkbox" wire:model="build_steps" value="npm_build" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                                <span><strong class="font-mono text-main">npm ci &amp;&amp; npm run build</strong></span>
                            </label>
                            <label class="flex items-center gap-2 text-xs text-main cursor-pointer">
                                <input type="checkbox" wire:model="build_steps" value="artisan_migrate" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                                <span><strong class="font-mono text-main">php artisan migrate</strong> (--force)</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs text-main cursor-pointer">
                                <input type="checkbox" wire:model="build_steps" value="artisan_cache" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                                <span><strong class="font-mono text-main">php artisan config:cache &amp;&amp; route:cache</strong></span>
                            </label>
                        </div>
                    </div>

                    <!-- Auto-deploy -->
                    <label class="flex items-center gap-2 text-xs text-main cursor-pointer">
                        <input type="checkbox" wire:model="auto_deploy" class="rounded-sm bg-card border-border text-primary-500 focus:ring-primary-500">
                        <span>Automatically trigger deployment when webhook is pinged</span>
                    </label>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" wire:click="$set('showCreateModal', false)" variant="ghost" size="sm">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">Save &amp; Generate Deploy Key</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Deploy Key & Webhook Modal -->
    @if ($showKeyModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-xl w-full p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-main mb-1">Deploy Key &amp; Webhook Details</h3>
                <p class="text-xs text-muted mb-4">Add this public key to GitHub/GitLab as a Read-Only Deploy Key, and paste the webhook URL into your repository settings.</p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Public SSH Deploy Key</label>
                        <textarea readonly rows="4" class="block w-full px-3 py-2 rounded-[16px] bg-shell border border-border text-primary-500 font-mono text-[11px] select-all outline-hidden">{{ $activePublicKey }}</textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Webhook URL (POST trigger)</label>
                        <input readonly type="text" value="{{ $activeWebhookUrl }}" class="block w-full px-3 py-2 rounded-full bg-shell border border-border text-emerald-500 font-mono text-[11px] select-all outline-hidden" />
                    </div>

                    <div class="bg-shell p-3 rounded-[16px] border border-border text-xs text-muted">
                        <strong>GitHub Instructions:</strong> Go to Repository &rarr; Settings &rarr; Deploy keys &rarr; Add deploy key. Paste the public key above. Under Webhooks, add the Webhook URL, select content-type <code class="text-main font-mono">application/json</code> and trigger on Push events.
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="button" wire:click="$set('showKeyModal', false)" variant="primary" size="sm">Done</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Release Logs Modal -->
    @if ($showLogsModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-2xl w-full p-6 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-border mb-4">
                    <h3 class="text-base font-bold text-main">Release Logs &middot; <span class="font-mono text-primary-500">{{ $activeReleaseTag }}</span></h3>
                    <button wire:click="$set('showLogsModal', false)" class="text-muted hover:text-main text-lg cursor-pointer">&times;</button>
                </div>
                <pre class="bg-shell border border-border rounded-[16px] p-4 font-mono text-xs text-main overflow-x-auto max-h-96 whitespace-pre-wrap">{{ $activeLogOutput }}</pre>
                <div class="mt-4 flex justify-end">
                    <x-ui.button type="button" wire:click="$set('showLogsModal', false)" variant="outline" size="sm">Close</x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
