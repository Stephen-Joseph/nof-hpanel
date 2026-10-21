<div>
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('accounts.show', $account) }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition">&larr; Back to Account: {{ $account->linux_username }}</a>
            </div>
            <h2 class="text-2xl font-bold text-white tracking-tight">File Manager &middot; {{ $account->primary_domain }}</h2>
            <p class="text-sm text-slate-400 mt-1">Browse, edit, and organize files inside <span class="font-mono text-slate-300">/home/{{ $account->linux_username }}</span> with strict isolation.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center gap-2">
            <button wire:click="openCreateModal(false)"
                class="inline-flex items-center px-3 py-1.5 border border-slate-700 rounded-lg text-xs font-semibold text-slate-200 bg-slate-800 hover:bg-slate-700 transition">
                + New File
            </button>
            <button wire:click="openCreateModal(true)"
                class="inline-flex items-center px-3 py-1.5 border border-slate-700 rounded-lg text-xs font-semibold text-slate-200 bg-slate-800 hover:bg-slate-700 transition">
                + New Folder
            </button>
            <button wire:click="loadDirectory('{{ $currentPath }}')"
                class="inline-flex items-center px-3 py-1.5 border border-transparent rounded-lg text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 transition">
                Refresh
            </button>
        </div>
    </div>

    <!-- Alert / Status -->
    @if (session()->has('status'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/20 p-4 text-sm text-emerald-400">
            {{ session('status') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="mb-6 rounded-lg bg-rose-500/10 border border-rose-500/20 p-4 text-sm text-rose-400">
            {{ session('error') }}
        </div>
    @endif

    <!-- Breadcrumb Bar -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3.5 mb-4 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-1.5 text-xs font-mono text-slate-300 overflow-x-auto">
            <button wire:click="loadDirectory('')" class="hover:text-indigo-400 font-semibold text-slate-400 transition">
                /home/{{ $account->linux_username }}
            </button>
            @if ($currentPath)
                @php
                    $parts = explode('/', trim($currentPath, '/'));
                    $build = '';
                @endphp
                @foreach ($parts as $p)
                    @php $build .= ($build ? '/' : '') . $p; @endphp
                    <span class="text-slate-600">/</span>
                    <button wire:click="loadDirectory('{{ $build }}')" class="hover:text-indigo-400 text-white transition">
                        {{ $p }}
                    </button>
                @endforeach
            @endif
        </div>
        @if ($parentPath)
            <button wire:click="loadDirectory('{{ $parentPath }}')"
                class="text-xs font-medium text-indigo-400 hover:text-indigo-300 shrink-0 ml-4 transition">
                &uarr; Up One Level
            </button>
        @endif
    </div>

    <!-- Files Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
            <thead class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                <tr>
                    <th scope="col" class="px-6 py-3">Name</th>
                    <th scope="col" class="px-6 py-3">Size</th>
                    <th scope="col" class="px-6 py-3">Permissions</th>
                    <th scope="col" class="px-6 py-3">Last Modified</th>
                    <th scope="col" class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 text-slate-300">
                @forelse ($entries as $item)
                    <tr class="hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-3 whitespace-nowrap font-medium text-white flex items-center gap-2.5">
                            @if ($item['is_dir'])
                                <svg class="w-4 h-4 text-amber-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
                                </svg>
                                <button wire:click="loadDirectory('{{ $item['path'] }}')" class="hover:text-indigo-400 font-semibold transition text-left">
                                    {{ $item['name'] }}
                                </button>
                            @else
                                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                @if (!empty($item['is_editable']))
                                    <button wire:click="editFile('{{ $item['path'] }}')" class="hover:text-indigo-400 font-normal transition text-left">
                                        {{ $item['name'] }}
                                    </button>
                                @else
                                    <span class="font-normal text-slate-200">{{ $item['name'] }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap font-mono text-xs text-slate-400">
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
                        <td class="px-6 py-3 whitespace-nowrap font-mono text-xs text-slate-400">
                            {{ $item['mode'] ?? '0644' }}
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-400">
                            {{ isset($item['mod_time']) ? \Carbon\Carbon::parse($item['mod_time'])->diffForHumans() : '&mdash;' }}
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap text-right text-xs space-x-2">
                            @if (!empty($item['is_editable']))
                                <button wire:click="editFile('{{ $item['path'] }}')" class="text-indigo-400 hover:text-indigo-300 font-medium transition">
                                    Edit
                                </button>
                            @endif
                            @if (($item['extension'] ?? '') === 'zip')
                                <button wire:click="extractZip('{{ $item['path'] }}')" class="text-emerald-400 hover:text-emerald-300 font-medium transition">
                                    Extract
                                </button>
                            @endif
                            <button wire:click="openRenameModal('{{ $item['path'] }}')" class="text-slate-400 hover:text-slate-200 transition">
                                Rename
                            </button>
                            <button wire:click="openChmodModal('{{ $item['path'] }}', '{{ $item['mode'] ?? '0644' }}')" class="text-slate-400 hover:text-slate-200 transition font-mono">
                                Perms
                            </button>
                            <button wire:click="deleteItem('{{ $item['path'] }}')" wire:confirm="Are you sure you want to delete '{{ $item['name'] }}'?"
                                class="text-rose-400 hover:text-rose-300 font-medium transition">
                                Delete
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-slate-500 text-sm">
                            Directory is empty.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Inline File Editor Modal -->
    @if ($isEditing)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-4xl w-full p-6 shadow-2xl flex flex-col max-h-[90vh]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800 mb-4">
                    <div>
                        <h3 class="text-base font-semibold text-white">Editing: {{ $editingFileName }}</h3>
                        <p class="text-xs font-mono text-slate-500">{{ $editingFilePath }}</p>
                    </div>
                    <button wire:click="$set('isEditing', false)" class="text-slate-400 hover:text-white">&times;</button>
                </div>

                <div class="flex-1 overflow-hidden">
                    <textarea wire:model="editingContent" rows="18"
                        class="w-full h-full font-mono text-xs bg-slate-950 border border-slate-800 rounded-lg text-emerald-400 p-4 focus:ring-1 focus:ring-indigo-500 resize-none"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-slate-800 mt-4">
                    <button wire:click="$set('isEditing', false)"
                        class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold transition">
                        Cancel
                    </button>
                    <button wire:click="saveFile"
                        class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm transition">
                        Save Changes
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Create Modal -->
    @if ($isCreating)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Create {{ $newItemIsDir ? 'New Folder' : 'New File' }}</h3>
                <form wire:submit.prevent="createItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Name</label>
                        <input type="text" wire:model="newItemName" placeholder="{{ $newItemIsDir ? 'assets' : 'script.php' }}"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('newItemName') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="$set('isCreating', false)"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm">
                            Create
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Rename Modal -->
    @if ($isRenaming)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Rename Item</h3>
                <form wire:submit.prevent="renameItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">New Name</label>
                        <input type="text" wire:model="renamingNewName"
                            class="w-full rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('renamingNewName') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="$set('isRenaming', false)"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm">
                            Rename
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Chmod Modal -->
    @if ($isChmodding)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/80 flex items-center justify-center p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-white mb-2">Change Permissions</h3>
                <form wire:submit.prevent="chmodItem" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Octal Mode (e.g. 0644, 0755)</label>
                        <input type="text" wire:model="chmodMode"
                            class="w-full font-mono rounded-lg bg-slate-800 border-slate-700 text-white text-sm px-3 py-2 border focus:ring-1 focus:ring-indigo-500">
                        @error('chmodMode') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="$set('isChmodding', false)"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-sm">
                            Apply Permissions
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

