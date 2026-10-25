<div>
    <!-- Header -->
    <x-layout.page-header 
        title="File Manager &middot; {{ $account->primary_domain }}" 
        subtitle="Browse, edit, upload, and organize files inside /home/{{ $account->linux_username }} with strict isolation."
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Account
                </x-ui.button>
                <x-ui.button wire:click="openUploadModal" variant="outline" size="sm">
                    <svg class="w-4 h-4 mr-1.5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Upload
                </x-ui.button>
                <x-ui.button wire:click="openCreateModal(false)" variant="outline" size="sm">
                    + New File
                </x-ui.button>
                <x-ui.button wire:click="openCreateModal(true)" variant="outline" size="sm">
                    + New Folder
                </x-ui.button>
                <x-ui.button wire:click="loadDirectory('{{ $currentPath }}')" variant="primary" size="sm">
                    Refresh
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-layout.page-header>

    <!-- Alert / Status -->
    @if (session()->has('status'))
        <div class="mb-6 rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Selection Bar -->
    @if (count($selectedItems) > 0)
        <div class="mb-4 bg-primary-500/10 border border-primary-500/20 rounded-[16px] px-4 py-3 flex items-center justify-between text-xs animate-in fade-in">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-primary-600 dark:text-primary-400">{{ count($selectedItems) }} item(s) selected</span>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button wire:click="openArchiveModal" variant="primary" size="xs">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                    </svg>
                    Archive Selected
                </x-ui.button>
                <x-ui.button wire:click="$set('selectedItems', [])" variant="ghost" size="xs">
                    Clear Selection
                </x-ui.button>
            </div>
        </div>
    @endif

    <!-- Breadcrumb Bar -->
    <div class="bg-card border border-border rounded-[20px] p-3.5 mb-4 flex items-center justify-between shadow-xs">
        <div class="flex items-center gap-1.5 text-xs font-mono text-muted overflow-x-auto">
            <button wire:click="loadDirectory('')" class="hover:text-primary-500 font-semibold text-main transition cursor-pointer">
                /home/{{ $account->linux_username }}
            </button>
            @if ($currentPath)
                @php
                    $parts = explode('/', trim($currentPath, '/'));
                    $build = '';
                @endphp
                @foreach ($parts as $p)
                    @php $build .= ($build ? '/' : '') . $p; @endphp
                    <span class="text-muted/50">/</span>
                    <button wire:click="loadDirectory('{{ $build }}')" class="hover:text-primary-500 text-main transition cursor-pointer">
                        {{ $p }}
                    </button>
                @endforeach
            @endif
        </div>
        @if ($parentPath)
            <button wire:click="loadDirectory('{{ $parentPath }}')"
                class="text-xs font-medium text-primary-500 hover:text-primary-600 shrink-0 ml-4 transition cursor-pointer">
                &uarr; Up One Level
            </button>
        @endif
    </div>

    <!-- Files Table -->
    <x-ui.card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-shell/50 text-muted font-medium">
                        <th class="w-10 px-4 py-3 text-center">
                            <input type="checkbox" wire:click="toggleSelectAll" @checked(count($entries) > 0 && count($selectedItems) === count($entries)) class="rounded border-border text-primary-500 focus:ring-primary-500/20 cursor-pointer">
                        </th>
                        <th class="py-3 px-4">Name</th>
                        <th class="py-3 px-6">Size</th>
                        <th class="py-3 px-6">Permissions</th>
                        <th class="py-3 px-6">Last Modified</th>
                        <th class="py-3 px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-main">
                    @forelse ($entries as $item)
                        @php
                            $ext = strtolower($item['extension'] ?? '');
                            $name = strtolower($item['name'] ?? '');
                            $isArchive = in_array($ext, ['zip', 'tgz', 'gz', 'tar'], true) || str_ends_with($name, '.tar.gz');
                        @endphp
                        <tr class="hover:bg-shell/40 transition-colors {{ in_array($item['path'], $selectedItems) ? 'bg-primary-500/5' : '' }}">
                            <td class="w-10 px-4 py-3 text-center">
                                <input type="checkbox" wire:model.live="selectedItems" value="{{ $item['path'] }}" class="rounded border-border text-primary-500 focus:ring-primary-500/20 cursor-pointer">
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-medium flex items-center gap-2.5">
                                @if ($item['is_dir'])
                                    <svg class="w-4 h-4 text-amber-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
                                    </svg>
                                    <button wire:click="loadDirectory('{{ $item['path'] }}')" class="hover:text-primary-500 font-semibold transition text-left cursor-pointer">
                                        {{ $item['name'] }}
                                    </button>
                                @elseif ($isArchive)
                                    <svg class="w-4 h-4 text-indigo-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                    </svg>
                                    <span class="font-normal text-main">{{ $item['name'] }}</span>
                                @else
                                    <svg class="w-4 h-4 text-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                    @if (!empty($item['is_editable']))
                                        <button wire:click="editFile('{{ $item['path'] }}')" class="hover:text-primary-500 font-normal transition text-left cursor-pointer">
                                            {{ $item['name'] }}
                                        </button>
                                    @else
                                        <span class="font-normal text-main">{{ $item['name'] }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap font-mono text-xs text-muted">
                                @if ($item['is_dir'])
                                    &mdash;
                                @else
                                    @php
                                        $size = $item['size'] ?? 0;
                                        $formatted = $size >= 1048576 ? round($size / 1048576, 2) . ' MB' : ($size >= 1024 ? round($size / 1024, 1) . ' KB' : $size . ' B');
                                    @endphp
                                    {{ $formatted }}
                                @endif
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap font-mono text-xs text-muted">
                                {{ $item['mode'] ?? '0644' }}
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap text-xs text-muted">
                                {{ isset($item['mod_time']) ? \Carbon\Carbon::parse($item['mod_time'])->diffForHumans() : '&mdash;' }}
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap text-right text-xs space-x-2">
                                @if (!empty($item['is_editable']))
                                    <button wire:click="editFile('{{ $item['path'] }}')" class="text-primary-500 hover:text-primary-600 font-medium transition cursor-pointer">
                                        Edit
                                    </button>
                                @endif
                                @if ($isArchive)
                                    <button wire:click="extractHere('{{ $item['path'] }}')" title="Extract in current folder" class="text-emerald-500 hover:text-emerald-600 font-medium transition cursor-pointer">
                                        Extract here
                                    </button>
                                    <button wire:click="openExtractModal('{{ $item['path'] }}')" title="Extract to specific folder" class="text-emerald-600 hover:text-emerald-700 font-medium transition cursor-pointer">
                                        Extract to...
                                    </button>
                                @endif
                                <button wire:click="openArchiveModal('{{ $item['path'] }}')" title="Create zip archive" class="text-indigo-500 hover:text-indigo-600 font-medium transition cursor-pointer">
                                    Archive
                                </button>
                                <button wire:click="openRenameModal('{{ $item['path'] }}')" class="text-muted hover:text-main transition cursor-pointer">
                                    Rename
                                </button>
                                <button wire:click="openChmodModal('{{ $item['path'] }}', '{{ $item['mode'] ?? '0644' }}')" class="text-muted hover:text-main transition font-mono cursor-pointer">
                                    Perms
                                </button>
                                <button wire:click="deleteItem('{{ $item['path'] }}')" wire:confirm="Are you sure you want to delete '{{ $item['name'] }}'?"
                                    class="text-rose-500 hover:text-rose-600 font-medium transition cursor-pointer">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted text-xs">
                                Directory is empty.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <!-- Upload Modal -->
    @if ($isUploading)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-lg w-full p-6 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-border mb-4">
                    <h3 class="text-base font-semibold text-main">Upload Files</h3>
                    <button wire:click="$set('isUploading', false)" class="text-muted hover:text-main text-lg leading-none cursor-pointer">&times;</button>
                </div>

                <form wire:submit.prevent="uploadFiles" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Target Folder</label>
                        <div class="flex gap-2">
                            <x-ui.input type="text" wire:model="uploadTargetFolder" placeholder="public_html" class="flex-1 font-mono text-xs" />
                            <x-ui.button type="button" wire:click="$set('uploadTargetFolder', '{{ $currentPath ?: 'public_html' }}')" variant="outline" size="sm">
                                Current
                            </x-ui.button>
                        </div>
                        <p class="text-[11px] text-muted mt-1">Relative to /home/{{ $account->linux_username }}/</p>
                        @error('uploadTargetFolder') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div x-data="{ uploading: false, progress: 0 }"
                         x-on:livewire-upload-start="uploading = true"
                         x-on:livewire-upload-finish="uploading = false"
                         x-on:livewire-upload-error="uploading = false"
                         x-on:livewire-upload-progress="progress = $event.detail.progress">
                        <label class="block text-xs font-medium text-muted mb-1.5">
                            Select Files (Allowed: .zip, .tar.gz, .tgz, normal web files; max {{ config('hpanel.file_manager.max_upload_size_mb', 100) }} MB)
                        </label>
                        <input type="file" wire:model="uploads" multiple
                            class="block w-full text-xs text-muted file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-primary-500/10 file:text-primary-600 hover:file:bg-primary-500/20 cursor-pointer border border-border rounded-[14px] p-2 bg-shell/50" />

                        <!-- Progress Bar during browser upload -->
                        <div x-show="uploading" class="w-full mt-3">
                            <div class="flex justify-between text-xs text-muted mb-1">
                                <span>Uploading to browser...</span>
                                <span x-text="`${progress}%`"></span>
                            </div>
                            <div class="w-full bg-shell rounded-full h-2 overflow-hidden border border-border">
                                <div class="bg-primary-500 h-2 rounded-full transition-all duration-150" :style="`width: ${progress}%`"></div>
                            </div>
                        </div>

                        @error('uploads') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        @error('uploads.*') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <!-- Transferring to Agent indicator -->
                    <div wire:loading wire:target="uploadFiles" class="p-3 rounded-xl bg-primary-500/10 border border-primary-500/20 text-xs text-primary-600 dark:text-primary-400 flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 shrink-0 text-primary-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Transmitting chunked files to server agent and verifying...</span>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-border mt-4">
                        <x-ui.button type="button" wire:click="$set('isUploading', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">
                            Upload to Server
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Extract Modal -->
    @if ($isExtracting)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-border mb-4">
                    <h3 class="text-base font-semibold text-main">Extract Archive</h3>
                    <button wire:click="$set('isExtracting', false)" class="text-muted hover:text-main text-lg leading-none cursor-pointer">&times;</button>
                </div>

                <form wire:submit.prevent="extractToFolder" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Archive</label>
                        <x-ui.input type="text" :value="basename($extractArchivePath)" disabled class="bg-shell/50 font-mono text-xs" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Target Destination Directory</label>
                        <x-ui.input type="text" wire:model="extractDestinationDir" placeholder="public_html" class="font-mono text-xs" />
                        <p class="text-[11px] text-muted mt-1">Relative to /home/{{ $account->linux_username }}/</p>
                        @error('extractDestinationDir') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div wire:loading wire:target="extractToFolder" class="p-3 rounded-xl bg-primary-500/10 border border-primary-500/20 text-xs text-primary-600 dark:text-primary-400 flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 shrink-0 text-primary-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Extracting files safely on server node...</span>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-border mt-4">
                        <x-ui.button type="button" wire:click="$set('isExtracting', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">
                            Extract
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Archive Modal -->
    @if ($isArchiving)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-border mb-4">
                    <h3 class="text-base font-semibold text-main">Create Zip Archive</h3>
                    <button wire:click="$set('isArchiving', false)" class="text-muted hover:text-main text-lg leading-none cursor-pointer">&times;</button>
                </div>

                <form wire:submit.prevent="createArchive" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Items to Archive ({{ count($archiveSources) }})</label>
                        <div class="max-h-32 overflow-y-auto p-2 bg-shell rounded-xl border border-border text-xs font-mono text-muted space-y-1">
                            @foreach ($archiveSources as $src)
                                <div class="truncate">&bull; {{ basename($src) }}</div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Archive Filename</label>
                        <x-ui.input type="text" wire:model="archiveDestinationName" placeholder="archive.zip" class="font-mono text-xs" />
                        <p class="text-[11px] text-muted mt-1">Must end with .zip</p>
                        @error('archiveDestinationName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div wire:loading wire:target="createArchive" class="p-3 rounded-xl bg-primary-500/10 border border-primary-500/20 text-xs text-primary-600 dark:text-primary-400 flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 shrink-0 text-primary-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Creating zip archive on server node...</span>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-border mt-4">
                        <x-ui.button type="button" wire:click="$set('isArchiving', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">
                            Create Archive
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Inline File Editor Modal -->
    @if ($isEditing)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-4xl w-full p-6 shadow-2xl flex flex-col max-h-[90vh]">
                <div class="flex items-center justify-between pb-3 border-b border-border mb-4">
                    <div>
                        <h3 class="text-base font-semibold text-main">Editing: {{ $editingFileName }}</h3>
                        <p class="text-xs font-mono text-muted">{{ $editingFilePath }}</p>
                    </div>
                    <button wire:click="$set('isEditing', false)" class="text-muted hover:text-main text-lg leading-none cursor-pointer">&times;</button>
                </div>

                <div class="flex-1 overflow-hidden">
                    <textarea wire:model="editingContent" rows="18"
                        class="w-full h-full font-mono text-xs bg-shell border border-border rounded-[16px] text-emerald-500 dark:text-emerald-400 p-4 focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-hidden resize-none"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-border mt-4">
                    <x-ui.button wire:click="$set('isEditing', false)" variant="ghost" size="sm">
                        Cancel
                    </x-ui.button>
                    <x-ui.button wire:click="saveFile" variant="primary" size="sm">
                        Save Changes
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-2">Create {{ $newItemIsDir ? 'New Folder' : 'New File' }}</h3>
                <form wire:submit.prevent="createItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Name</label>
                        <x-ui.input type="text" wire:model="newItemName" placeholder="{{ $newItemIsDir ? 'assets' : 'script.php' }}" />
                        @error('newItemName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" wire:click="$set('isCreating', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Create
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Rename Modal -->
    @if ($isRenaming)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-2">Rename Item</h3>
                <form wire:submit.prevent="renameItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">New Name</label>
                        <x-ui.input type="text" wire:model="renamingNewName" />
                        @error('renamingNewName') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" wire:click="$set('isRenaming', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Rename
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Chmod Modal -->
    @if ($isChmodding)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-[24px] max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-main mb-2">Change Permissions</h3>
                <form wire:submit.prevent="chmodItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted mb-1.5">Octal Mode (e.g. 0644, 0755)</label>
                        <x-ui.input type="text" wire:model="chmodMode" class="font-mono" />
                        @error('chmodMode') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button type="button" wire:click="$set('isChmodding', false)" variant="ghost" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            Apply Permissions
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
