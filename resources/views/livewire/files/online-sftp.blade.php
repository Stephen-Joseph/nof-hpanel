<div class="space-y-6" x-data="{ selectedAll: false }">
    <!-- Header with Breadcrumbs & Quota -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-primary-500/10 text-primary-500">
                        ⚡
                    </span>
                    <h2 class="text-xl font-bold text-[var(--text)] tracking-tight">Online SFTP &middot; {{ $account->primary_domain }}</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                        mTLS &middot; Isolated
                    </span>
                </div>
                <p class="text-xs text-[var(--text-muted)] mt-1">
                    Direct server file access inside <span class="font-mono text-primary-500">/home/{{ $account->linux_username }}</span> without desktop SFTP clients.
                </p>
            </div>

            <!-- Storage Quota Bar -->
            <div class="lg:w-80 bg-[var(--bg-shell)] border border-[var(--border)] rounded-[16px] p-3">
                <div class="flex items-center justify-between text-xs mb-1.5">
                    <span class="font-semibold text-[var(--text)]">Storage Quota</span>
                    <span class="text-[var(--text-muted)] font-mono">
                        {{ number_format($account->current_storage_mb, 1) }} / {{ $account->package->storage_mb }} MB
                        ({{ $account->storagePercentage() }}%)
                    </span>
                </div>
                <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                    <div 
                        class="h-full rounded-full transition-all duration-300 {{ $account->storagePercentage() > 90 ? 'bg-rose-500' : ($account->storagePercentage() > 75 ? 'bg-amber-500' : 'bg-primary-500') }}"
                        style="width: {{ min(100, $account->storagePercentage()) }}%"
                    ></div>
                </div>
            </div>
        </div>

        <!-- Breadcrumbs & Quick Search -->
        <div class="mt-6 pt-4 border-t border-[var(--border)] flex flex-wrap items-center justify-between gap-3">
            <nav class="flex items-center space-x-1 text-xs text-[var(--text-muted)] font-mono">
                <button 
                    wire:click="loadDirectory('')" 
                    class="hover:text-primary-500 px-2 py-1 rounded-md hover:bg-[var(--bg-shell)] transition flex items-center gap-1"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    ~
                </button>
                @php
                    $segments = array_filter(explode('/', trim($currentPath, '/')));
                    $builtPath = '';
                @endphp
                @foreach ($segments as $segment)
                    @php $builtPath .= ($builtPath === '' ? '' : '/') . $segment; @endphp
                    <span>/</span>
                    <button 
                        wire:click="loadDirectory('{{ $builtPath }}')" 
                        class="hover:text-primary-500 px-2 py-1 rounded-md hover:bg-[var(--bg-shell)] transition {{ $loop->last ? 'font-bold text-[var(--text)]' : '' }}"
                    >
                        {{ $segment }}
                    </button>
                @endforeach
            </nav>

            <div class="relative w-full sm:w-64">
                <input 
                    type="text" 
                    wire:model.live.debounce.250ms="searchQuery" 
                    placeholder="Filter current folder..." 
                    class="w-full text-xs rounded-full bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] pl-8 pr-3 py-1.5 focus:border-primary-500 focus:outline-hidden"
                />
                <svg class="w-3.5 h-3.5 text-[var(--text-muted)] absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    @if (session()->has('status'))
        <div class="rounded-[20px] bg-emerald-500/10 border border-emerald-500/20 p-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="rounded-[20px] bg-rose-500/10 border border-rose-500/20 p-4 text-xs font-semibold text-rose-500 dark:text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Toolbar -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[20px] p-3 flex flex-wrap items-center justify-between gap-2 shadow-xs">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-ui.button wire:click="goUp" variant="outline" size="sm" :disabled="$currentPath === '' || $currentPath === '.'">
                <span wire:loading.remove wire:target="goUp">↑ Up</span>
                <span wire:loading wire:target="goUp">...</span>
            </x-ui.button>
            <x-ui.button wire:click="loadDirectory('{{ $currentPath }}')" variant="outline" size="sm">
                <span wire:loading.remove wire:target="loadDirectory">↻ Refresh</span>
                <span wire:loading wire:target="loadDirectory">...</span>
            </x-ui.button>
            <div class="h-4 w-px bg-[var(--border)] mx-1"></div>
            <x-ui.button wire:click="openCreateFolderModal" variant="outline" size="sm">
                + New Folder
            </x-ui.button>
            <x-ui.button wire:click="openCreateFileModal" variant="outline" size="sm">
                + New File
            </x-ui.button>
            <x-ui.button wire:click="openUploadModal" variant="primary" size="sm">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Upload
            </x-ui.button>
        </div>

        <div class="flex items-center gap-1.5">
            @if (count($selectedItems) > 0)
                <span class="text-xs text-[var(--text-muted)] mr-2 font-mono">
                    {{ count($selectedItems) }} selected
                </span>
                <x-ui.button wire:click="openArchiveModal" variant="outline" size="sm">
                    📦 Archive
                </x-ui.button>
                <x-ui.button wire:click="openBulkDeleteModal" variant="danger" size="sm">
                    🗑 Delete ({{ count($selectedItems) }})
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Main Two-Pane Section -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Left Pane: Folder Tree -->
        <div class="lg:col-span-1 bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-4 shadow-sm flex flex-col">
            <h3 class="text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-3 px-2">Folders</h3>
            <div class="space-y-1">
                <button 
                    wire:click="loadDirectory('')" 
                    class="w-full text-left px-2.5 py-1.5 rounded-lg text-xs font-mono flex items-center justify-between transition {{ $currentPath === '' || $currentPath === '.' ? 'bg-primary-500/10 text-primary-500 font-bold' : 'text-[var(--text)] hover:bg-[var(--bg-shell)]' }}"
                >
                    <span class="flex items-center gap-2">
                        <span>📁</span>
                        <span>/home/{{ $account->linux_username }}</span>
                    </span>
                </button>

                @foreach ($folderTree as $dir)
                    <button 
                        wire:click="loadDirectory('{{ $dir }}')" 
                        class="w-full text-left pl-6 pr-2.5 py-1.5 rounded-lg text-xs font-mono flex items-center justify-between transition {{ str_starts_with($currentPath, $dir) ? 'bg-primary-500/10 text-primary-500 font-bold' : 'text-[var(--text)] hover:bg-[var(--bg-shell)]' }}"
                    >
                        <span class="flex items-center gap-2">
                            <span>{{ $dir === 'public_html' ? '🌐' : ($dir === 'logs' ? '📜' : ($dir === 'backups' ? '💾' : '📁')) }}</span>
                            <span>{{ $dir }}</span>
                        </span>
                        @if ($currentPath === $dir)
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500"></span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Right Pane: File Table -->
        <div class="lg:col-span-3 bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] overflow-hidden shadow-sm flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-[var(--border)] bg-[var(--bg-shell)]/50 text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider select-none">
                            <th class="p-3 w-8 text-center">
                                <input 
                                    type="checkbox" 
                                    class="rounded-sm border-[var(--border)] text-primary-500 focus:ring-0"
                                    x-on:change="
                                        let boxes = $el.closest('table').querySelectorAll('tbody input[type=checkbox]');
                                        boxes.forEach(b => { b.checked = $el.checked; b.dispatchEvent(new Event('change')) });
                                    "
                                />
                            </th>
                            <th class="p-3 cursor-pointer hover:text-[var(--text)] transition" wire:click="setSort('name')">
                                Name {{ $sortBy === 'name' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' }}
                            </th>
                            <th class="p-3 cursor-pointer hover:text-[var(--text)] transition" wire:click="setSort('type')">
                                Type {{ $sortBy === 'type' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' }}
                            </th>
                            <th class="p-3 cursor-pointer hover:text-[var(--text)] transition text-right" wire:click="setSort('size')">
                                Size {{ $sortBy === 'size' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' }}
                            </th>
                            <th class="p-3 cursor-pointer hover:text-[var(--text)] transition" wire:click="setSort('mod_time')">
                                Modified
                            </th>
                            <th class="p-3">Perms</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-xs text-[var(--text)]">
                        @forelse ($displayEntries as $entry)
                            @php
                                $isDir = ($entry['type'] ?? '') === 'directory';
                                $isSymlink = ($entry['type'] ?? '') === 'symlink';
                                $ext = strtolower($entry['extension'] ?? '');
                                $isArchive = in_array($ext, ['zip', 'tar', 'gz', 'tgz'], true);
                                $isEditable = !empty($entry['is_editable']);
                                $relPath = $currentPath !== '' ? $currentPath . '/' . $entry['name'] : $entry['name'];
                            @endphp
                            <tr class="hover:bg-[var(--bg-shell)]/40 transition group">
                                <td class="p-3 text-center">
                                    <input 
                                        type="checkbox" 
                                        wire:model.live="selectedItems" 
                                        value="{{ $relPath }}"
                                        class="rounded-sm border-[var(--border)] text-primary-500 focus:ring-0"
                                    />
                                </td>
                                <td class="p-3 font-mono flex items-center gap-2">
                                    <span class="text-base select-none">
                                        @if ($isDir) 📁
                                        @elseif ($isSymlink) 🔗
                                        @elseif ($isArchive) 📦
                                        @elseif (in_array($ext, ['php', 'js', 'html', 'css', 'json', 'sql', 'sh'], true)) 📄
                                        @elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'], true)) 🖼️
                                        @else 📝
                                        @endif
                                    </span>
                                    @if ($isDir)
                                        <button 
                                            wire:click="loadDirectory('{{ $relPath }}')" 
                                            class="font-semibold text-primary-500 hover:underline text-left"
                                        >
                                            {{ $entry['name'] }}
                                        </button>
                                    @else
                                        <span class="{{ $isEditable ? 'text-[var(--text)]' : 'text-[var(--text-muted)]' }}">
                                            {{ $entry['name'] }}
                                        </span>
                                    @endif
                                </td>
                                <td class="p-3 text-[var(--text-muted)] capitalize">
                                    {{ $entry['type'] }}
                                </td>
                                <td class="p-3 text-right font-mono text-[var(--text-muted)]">
                                    @if ($isDir)
                                        &mdash;
                                    @else
                                        @php
                                            $bytes = (int) ($entry['size'] ?? 0);
                                            $sizeStr = $bytes < 1024 ? "{$bytes} B" : ($bytes < 1048576 ? round($bytes/1024, 1).' KB' : round($bytes/1048576, 1).' MB');
                                        @endphp
                                        {{ $sizeStr }}
                                    @endif
                                </td>
                                <td class="p-3 text-[var(--text-muted)]">
                                    {{ isset($entry['mod_time']) ? \Carbon\Carbon::parse($entry['mod_time'])->format('M d, H:i') : '&mdash;' }}
                                </td>
                                <td class="p-3 font-mono text-[11px] text-[var(--text-muted)]">
                                    {{ $entry['mode'] ?? '0644' }}
                                </td>
                                <td class="p-3 text-right">
                                    <div class="flex items-center justify-end gap-1 opacity-80 group-hover:opacity-100 transition">
                                        @if ($isEditable)
                                            <button 
                                                wire:click="openEditor('{{ $relPath }}')" 
                                                class="px-2 py-1 rounded-sm text-xs font-semibold bg-primary-500/10 text-primary-500 hover:bg-primary-500/20 transition"
                                                title="Edit Text"
                                            >
                                                Edit
                                            </button>
                                        @endif

                                        @if (!$isDir)
                                            <button 
                                                wire:click="downloadFile('{{ $relPath }}')" 
                                                class="p-1 rounded-sm text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                                                title="Download File"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                </svg>
                                            </button>
                                        @endif

                                        @if ($isArchive)
                                            <button 
                                                wire:click="openExtractModal('{{ $relPath }}')" 
                                                class="px-2 py-1 rounded-sm text-xs font-semibold bg-amber-500/10 text-amber-500 hover:bg-amber-500/20 transition"
                                                title="Extract Archive"
                                            >
                                                Extract
                                            </button>
                                        @endif

                                        <button 
                                            wire:click="openRenameModal('{{ $relPath }}')" 
                                            class="p-1 rounded-sm text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                                            title="Rename"
                                        >
                                            ✏
                                        </button>
                                        <button 
                                            wire:click="openMoveModal('{{ $relPath }}')" 
                                            class="p-1 rounded-sm text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                                            title="Move"
                                        >
                                            🚚
                                        </button>
                                        <button 
                                            wire:click="openCopyModal('{{ $relPath }}')" 
                                            class="p-1 rounded-sm text-[var(--text-muted)] hover:text-[var(--text)] hover:bg-[var(--bg-shell)] transition"
                                            title="Copy"
                                        >
                                            📋
                                        </button>
                                        <button 
                                            wire:click="openDeleteModal('{{ $relPath }}')" 
                                            class="p-1 rounded-sm text-rose-500 hover:bg-rose-500/10 transition"
                                            title="Delete"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-8 text-center text-xs text-[var(--text-muted)]">
                                    This directory is empty.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Drag & Drop Upload Modal / Zone -->
    @if ($isUploading)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isUploading', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-lg bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-2">Upload Files to /{{ $currentPath }}</h3>
                    <p class="text-xs text-[var(--text-muted)] mb-4">
                        Max file size: {{ config('hpanel.file_manager.max_upload_size_mb', 100) }} MB per file. Chunked upload protocol via signed agent actions.
                    </p>

                    <!-- Drag & Drop Zone -->
                    <div 
                        class="border-2 border-dashed border-[var(--border)] rounded-[16px] p-6 text-center hover:border-primary-500 transition cursor-pointer bg-[var(--bg-shell)]/30"
                        x-data="{ isDragging: false }"
                        x-on:dragover.prevent="isDragging = true"
                        x-on:dragleave.prevent="isDragging = false"
                        x-on:drop.prevent="isDragging = false"
                    >
                        <input 
                            type="file" 
                            wire:model="uploads" 
                            multiple 
                            class="hidden" 
                            id="sftp-file-input" 
                        />
                        <label for="sftp-file-input" class="cursor-pointer block">
                            <svg class="w-10 h-10 text-primary-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <span class="text-xs font-semibold text-[var(--text)] block">Click to browse or drag & drop files here</span>
                            <span class="text-[11px] text-[var(--text-muted)] block mt-1">Multi-file uploads supported</span>
                        </label>
                    </div>

                    <!-- Selected Files & Upload Progress -->
                    @if (count($uploads) > 0)
                        <div class="mt-4 space-y-2 max-h-48 overflow-y-auto">
                            @foreach ($uploads as $file)
                                <div class="bg-[var(--bg-shell)] p-2.5 rounded-lg text-xs flex flex-col gap-1">
                                    <div class="flex items-center justify-between">
                                        <span class="font-mono text-[var(--text)] truncate max-w-[280px]">{{ $file->getClientOriginalName() }}</span>
                                        <span class="text-[var(--text-muted)]">{{ round($file->getSize() / 1024, 1) }} KB</span>
                                    </div>
                                    @if (isset($uploadErrors[$file->getClientOriginalName()]))
                                        <span class="text-rose-500 text-[11px] font-semibold">{{ $uploadErrors[$file->getClientOriginalName()] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div wire:loading wire:target="uploads" class="mt-3 text-xs text-primary-500 font-semibold flex items-center gap-2">
                        <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        Preparing files...
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isUploading', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="uploadFiles" variant="primary" size="sm" :disabled="empty($uploads)">
                            <span wire:loading.remove wire:target="uploadFiles">Start Upload</span>
                            <span wire:loading wire:target="uploadFiles">Uploading chunks...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Inline Text Editor Modal -->
    @if ($isEditing)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isEditing', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-4xl bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl flex flex-col">
                    <div class="flex items-center justify-between mb-3 border-b border-[var(--border)] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-sm text-[var(--text)]">Editing:</span>
                            <span class="font-mono text-xs text-primary-500 bg-primary-500/10 px-2 py-0.5 rounded-md">{{ $editingFilePath }}</span>
                        </div>
                        <span class="text-xs text-[var(--text-muted)] font-mono">{{ strlen($editingContent) }} bytes</span>
                    </div>

                    <textarea 
                        wire:model="editingContent" 
                        rows="20" 
                        class="w-full font-mono text-xs bg-slate-950 text-slate-100 p-4 rounded-[16px] border border-slate-800 focus:outline-hidden focus:border-primary-500 resize-y leading-relaxed"
                    ></textarea>

                    <div class="mt-4 flex items-center justify-between">
                        <span class="text-[11px] text-[var(--text-muted)]">Atomic safe write with automatic rollback and user chown.</span>
                        <div class="flex items-center gap-2">
                            <x-ui.button wire:click="$set('isEditing', false)" variant="outline" size="sm">
                                Cancel
                            </x-ui.button>
                            <x-ui.button wire:click="saveEditor" variant="primary" size="sm">
                                <span wire:loading.remove wire:target="saveEditor">Save Changes</span>
                                <span wire:loading wire:target="saveEditor">Saving...</span>
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- New Folder Modal -->
    @if ($isCreatingFolder)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isCreatingFolder', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Create New Folder</h3>
                    <input 
                        type="text" 
                        wire:model="newFolderName" 
                        placeholder="folder_name" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isCreatingFolder', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="createFolder" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="createFolder">Create</span>
                            <span wire:loading wire:target="createFolder">Creating...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- New File Modal -->
    @if ($isCreatingFile)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isCreatingFile', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Create New File</h3>
                    <input 
                        type="text" 
                        wire:model="newFileName" 
                        placeholder="index.html" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isCreatingFile', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="createFile" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="createFile">Create</span>
                            <span wire:loading wire:target="createFile">Creating...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Rename Modal -->
    @if ($isRenaming)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isRenaming', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Rename Item</h3>
                    <input 
                        type="text" 
                        wire:model="renamingNewName" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isRenaming', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="renameItem" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="renameItem">Rename</span>
                            <span wire:loading wire:target="renameItem">Renaming...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Move Modal -->
    @if ($isMoving)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isMoving', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Move Item</h3>
                    <label class="text-[11px] text-[var(--text-muted)] block mb-1">Destination Directory inside Home</label>
                    <input 
                        type="text" 
                        wire:model="movingDestPath" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isMoving', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="moveItem" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="moveItem">Move</span>
                            <span wire:loading wire:target="moveItem">Moving...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Copy Modal -->
    @if ($isCopying)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isCopying', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Copy Item</h3>
                    <label class="text-[11px] text-[var(--text-muted)] block mb-1">Destination Path inside Home</label>
                    <input 
                        type="text" 
                        wire:model="copyingDestPath" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isCopying', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="copyItem" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="copyItem">Copy</span>
                            <span wire:loading wire:target="copyItem">Copying...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($isDeleting)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isDeleting', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-2">Confirm Delete</h3>
                    <p class="text-xs text-[var(--text-muted)] mb-4">
                        Are you sure you want to permanently delete 
                        <strong class="font-mono text-rose-500">{{ $deletingIsBulk ? count($selectedItems) . ' selected items' : $deletingTarget }}</strong>?
                        This cannot be undone.
                    </p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isDeleting', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="confirmDelete" variant="danger" size="sm">
                            <span wire:loading.remove wire:target="confirmDelete">Delete Permanently</span>
                            <span wire:loading wire:target="confirmDelete">Deleting...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Archive Modal -->
    @if ($isArchiving)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isArchiving', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Create ZIP Archive</h3>
                    <label class="text-[11px] text-[var(--text-muted)] block mb-1">Archive Name (must end with .zip)</label>
                    <input 
                        type="text" 
                        wire:model="archiveName" 
                        placeholder="archive.zip" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isArchiving', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="createArchive" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="createArchive">Create Archive</span>
                            <span wire:loading wire:target="createArchive">Archiving...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Extract Modal -->
    @if ($isExtracting)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-xs transition-opacity" wire:click="$set('isExtracting', false)"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-[var(--text)] mb-3">Extract Archive</h3>
                    <label class="text-[11px] text-[var(--text-muted)] block mb-1">Destination Directory inside Home</label>
                    <input 
                        type="text" 
                        wire:model="extractDestinationDir" 
                        class="w-full text-xs font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-4"
                    />
                    <div class="flex justify-end gap-2">
                        <x-ui.button wire:click="$set('isExtracting', false)" variant="outline" size="sm">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="extractArchive" variant="primary" size="sm">
                            <span wire:loading.remove wire:target="extractArchive">Extract</span>
                            <span wire:loading wire:target="extractArchive">Extracting...</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Session Freshness / PIN Unlock Modal -->
    @if ($isLocked)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity"></div>
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-sm bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-6 shadow-2xl text-center">
                    <div class="w-12 h-12 rounded-full bg-primary-500/10 text-primary-500 flex items-center justify-center mx-auto mb-3 text-xl">
                        🔒
                    </div>
                    <h3 class="text-base font-bold text-[var(--text)] mb-1">Online SFTP Locked</h3>
                    <p class="text-xs text-[var(--text-muted)] mb-4">
                        For security, please enter the account owner PIN or your login password to unlock SFTP file access.
                    </p>

                    <form wire:submit.prevent="unlockSession">
                        <input 
                            type="password" 
                            wire:model="pin" 
                            placeholder="Enter PIN or Password" 
                            class="w-full text-center tracking-widest text-sm font-mono rounded-lg bg-[var(--bg-shell)] border border-[var(--border)] text-[var(--text)] p-2.5 focus:border-primary-500 focus:outline-hidden mb-2"
                            autofocus
                        />
                        @error('pin')
                            <span class="text-rose-500 text-xs block mb-3">{{ $message }}</span>
                        @enderror

                        <div class="mt-4 flex justify-center gap-2">
                            <x-ui.button href="{{ route('accounts.show', $account) }}" variant="outline" size="sm">
                                Back to Account
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="sm">
                                <span wire:loading.remove wire:target="unlockSession">Unlock SFTP</span>
                                <span wire:loading wire:target="unlockSession">Verifying...</span>
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

