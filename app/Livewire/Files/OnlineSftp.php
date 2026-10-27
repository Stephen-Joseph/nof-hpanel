<?php

namespace App\Livewire\Files;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\AuditLog;
use App\Services\AgentClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnlineSftp extends Component
{
    use WithFileUploads;

    public Account $account;

    public string $currentPath = 'public_html';

    public ?string $parentPath = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $entries = [];

    /**
     * @var list<string>
     */
    public array $folderTree = [];

    public string $searchQuery = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    /**
     * @var list<string>
     */
    public array $selectedItems = [];

    // Session freshness & PIN state
    public bool $isLocked = false;

    public string $pin = '';

    // Editor modal state
    public bool $isEditing = false;

    public string $editingFilePath = '';

    public string $editingFileName = '';

    public string $editingContent = '';

    // Create item modals
    public bool $isCreatingFolder = false;

    public string $newFolderName = '';

    public bool $isCreatingFile = false;

    public string $newFileName = '';

    // Rename state
    public bool $isRenaming = false;

    public string $renamingTarget = '';

    public string $renamingNewName = '';

    // Move state
    public bool $isMoving = false;

    public string $movingTarget = '';

    public string $movingDestPath = '';

    // Copy state
    public bool $isCopying = false;

    public string $copyingTarget = '';

    public string $copyingDestPath = '';

    // Delete state
    public bool $isDeleting = false;

    public string $deletingTarget = '';

    public bool $deletingIsBulk = false;

    // Archive state
    public bool $isArchiving = false;

    public string $archiveName = 'archive.zip';

    // Extract state
    public bool $isExtracting = false;

    public string $extractArchivePath = '';

    public string $extractDestinationDir = 'public_html';

    // Upload state
    public bool $isUploading = false;

    /**
     * @var array<int, UploadedFile>
     */
    public array $uploads = [];

    /**
     * @var array<string, string>
     */
    public array $uploadErrors = [];

    public function mount(Account $account, AgentClient $client): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['server', 'package']);

        $this->checkSessionFreshnessOnMount();

        if (! $this->isLocked) {
            $this->loadDirectory($this->currentPath, $client);
            $this->loadFolderTree($client);
        }
    }

    protected function checkRateLimit(): void
    {
        $userId = auth()->id() ?? 0;
        $key = 'online_sftp_user:'.$userId;
        $maxAttempts = (int) config('hpanel.online_sftp.rate_limit_per_minute', 120);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Too many SFTP requests. Please wait {$seconds} seconds.");
        }

        RateLimiter::hit($key, 60);
    }

    protected function checkSessionFreshnessOnMount(): void
    {
        $unlockedAt = session('sftp_unlocked_at');
        $timeoutMinutes = (int) config('hpanel.online_sftp.session_timeout_minutes', 30);
        $timeoutSeconds = $timeoutMinutes * 60;

        if ($unlockedAt && (now()->timestamp - (int) $unlockedAt) < $timeoutSeconds) {
            $this->isLocked = false;

            return;
        }

        // Check if user authenticated recently (within timeout)
        $authTime = session('auth_time') ?? session('auth.password_confirmed_at');
        if ($authTime && (now()->timestamp - (int) $authTime) < $timeoutSeconds) {
            session(['sftp_unlocked_at' => now()->timestamp]);
            $this->isLocked = false;

            return;
        }

        // Default: locked requiring owner PIN or password
        $this->isLocked = true;
    }

    public function unlockSession(AgentClient $client): void
    {
        $this->authorize('view', $this->account);
        $this->checkRateLimit();

        $configuredPin = (string) config('hpanel.online_sftp.owner_pin', '123456');
        $user = auth()->user();

        $isValid = false;
        if ($this->pin !== '' && hash_equals($configuredPin, $this->pin)) {
            $isValid = true;
        } elseif ($user && $this->pin !== '' && Hash::check($this->pin, $user->password)) {
            $isValid = true;
        }

        if (! $isValid) {
            $this->addError('pin', 'Incorrect owner PIN or password.');

            return;
        }

        session(['sftp_unlocked_at' => now()->timestamp]);
        $this->isLocked = false;
        $this->pin = '';
        $this->resetErrorBag();

        $this->loadDirectory($this->currentPath, $client);
        $this->loadFolderTree($client);
    }

    public function loadDirectory(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);
        $this->checkRateLimit();

        if ($this->isLocked) {
            return;
        }

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.list',
                [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ]
            );

            if ($response['success']) {
                $this->currentPath = (string) ($response['data']['rel_path'] ?? $path);
                $this->parentPath = $response['data']['parent_path'] !== '' ? (string) $response['data']['parent_path'] : null;
                $this->entries = (array) ($response['data']['entries'] ?? []);
                $this->selectedItems = [];
            } else {
                session()->flash('error', 'SFTP list failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'SFTP connection error: '.$e->getMessage());
        }
    }

    public function loadFolderTree(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.list',
                [
                    'username' => $this->account->linux_username,
                    'path' => '',
                ]
            );

            if ($response['success']) {
                $dirs = [];
                foreach ($response['data']['entries'] ?? [] as $entry) {
                    if (($entry['type'] ?? '') === 'directory') {
                        $dirs[] = $entry['name'];
                    }
                }
                $this->folderTree = $dirs;
            }
        } catch (\Throwable $e) {
            // Non-blocking for folder tree sidebar
        }
    }

    public function goUp(AgentClient $client): void
    {
        if ($this->currentPath === '' || $this->currentPath === '.') {
            return;
        }

        $parts = explode('/', trim($this->currentPath, '/'));
        array_pop($parts);
        $newPath = implode('/', $parts);

        $this->loadDirectory($newPath, $client);
    }

    public function setSort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    // --- Create Folder ---

    public function openCreateFolderModal(): void
    {
        $this->newFolderName = '';
        $this->isCreatingFolder = true;
    }

    public function createFolder(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validateFilenameStrict($this->newFolderName);

        $targetPath = $this->currentPath !== '' ? $this->currentPath.'/'.$this->newFolderName : $this->newFolderName;

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.mkdir',
                [
                    'username' => $this->account->linux_username,
                    'path' => $targetPath,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.mkdir', $targetPath, 0);
                session()->flash('status', "Directory '{$this->newFolderName}' created successfully.");
                $this->isCreatingFolder = false;
                $this->newFolderName = '';
                $this->loadDirectory($this->currentPath, $client);
                $this->loadFolderTree($client);
            } else {
                session()->flash('error', 'Failed to create directory: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Create error: '.$e->getMessage());
        }
    }

    // --- Create File ---

    public function openCreateFileModal(): void
    {
        $this->newFileName = '';
        $this->isCreatingFile = true;
    }

    public function createFile(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validateFilenameStrict($this->newFileName);

        $targetPath = $this->currentPath !== '' ? $this->currentPath.'/'.$this->newFileName : $this->newFileName;

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.create_file',
                [
                    'username' => $this->account->linux_username,
                    'path' => $targetPath,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.create_file', $targetPath, 0);
                session()->flash('status', "File '{$this->newFileName}' created successfully.");
                $this->isCreatingFile = false;
                $this->newFileName = '';
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', 'Failed to create file: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Create error: '.$e->getMessage());
        }
    }

    // --- Rename ---

    public function openRenameModal(string $path): void
    {
        $this->renamingTarget = $path;
        $this->renamingNewName = basename($path);
        $this->isRenaming = true;
    }

    public function renameItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validateFilenameStrict($this->renamingNewName);

        $dir = dirname($this->renamingTarget);
        $targetPath = ($dir === '.' || $dir === '') ? $this->renamingNewName : $dir.'/'.$this->renamingNewName;

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.rename',
                [
                    'username' => $this->account->linux_username,
                    'source_path' => $this->renamingTarget,
                    'target_path' => $targetPath,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.rename', $this->renamingTarget.' -> '.$targetPath, 0);
                session()->flash('status', "Renamed to '{$this->renamingNewName}'.");
                $this->isRenaming = false;
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', 'Rename failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Rename error: '.$e->getMessage());
        }
    }

    // --- Move ---

    public function openMoveModal(string $path): void
    {
        $this->movingTarget = $path;
        $this->movingDestPath = dirname($path);
        $this->isMoving = true;
    }

    public function moveItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validate([
            'movingDestPath' => ['required', 'string', 'max:512'],
        ]);

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.move',
                [
                    'username' => $this->account->linux_username,
                    'source_path' => $this->movingTarget,
                    'target_path' => $this->movingDestPath,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.move', $this->movingTarget.' -> '.$this->movingDestPath, 0);
                session()->flash('status', "Moved successfully.");
                $this->isMoving = false;
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', 'Move failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Move error: '.$e->getMessage());
        }
    }

    // --- Copy ---

    public function openCopyModal(string $path): void
    {
        $this->copyingTarget = $path;
        $this->copyingDestPath = dirname($path).'/copy_of_'.basename($path);
        $this->isCopying = true;
    }

    public function copyItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validate([
            'copyingDestPath' => ['required', 'string', 'max:512'],
        ]);

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.copy',
                [
                    'username' => $this->account->linux_username,
                    'source_path' => $this->copyingTarget,
                    'target_path' => $this->copyingDestPath,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.copy', $this->copyingTarget.' -> '.$this->copyingDestPath, 0);
                session()->flash('status', "Copied successfully.");
                $this->isCopying = false;
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', 'Copy failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Copy error: '.$e->getMessage());
        }
    }

    // --- Delete ---

    public function openDeleteModal(string $path): void
    {
        $this->deletingTarget = $path;
        $this->deletingIsBulk = false;
        $this->isDeleting = true;
    }

    public function openBulkDeleteModal(): void
    {
        if (empty($this->selectedItems)) {
            return;
        }
        $this->deletingTarget = '';
        $this->deletingIsBulk = true;
        $this->isDeleting = true;
    }

    public function confirmDelete(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $targets = $this->deletingIsBulk ? $this->selectedItems : [$this->deletingTarget];

        $deletedCount = 0;
        foreach ($targets as $target) {
            if ($target === '' || $target === '.' || $target === '/') {
                continue;
            }

            try {
                $response = $client->execute(
                    $this->account->server,
                    'fs.delete',
                    [
                        'username' => $this->account->linux_username,
                        'path' => $target,
                    ]
                );

                if ($response['success']) {
                    $this->logAudit('fs.delete', $target, 0);
                    $deletedCount++;
                    if (str_ends_with($target, '.htaccess')) {
                        $this->triggerHtaccessSync();
                    }
                }
            } catch (\Throwable $e) {
                // Keep deleting remaining items
            }
        }

        $this->isDeleting = false;
        $this->selectedItems = [];
        session()->flash('status', "Deleted {$deletedCount} item(s).");
        $this->loadDirectory($this->currentPath, $client);
        $this->loadFolderTree($client);
    }

    // --- Edit Text File (< 1 MB) ---

    public function openEditor(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);
        $this->checkRateLimit();

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.read_text',
                [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ]
            );

            if ($response['success']) {
                $this->editingFilePath = $path;
                $this->editingFileName = basename($path);
                $this->editingContent = (string) ($response['data']['content'] ?? '');
                $this->isEditing = true;
            } else {
                session()->flash('error', 'Cannot edit: '.($response['error'] ?? 'File unreadable or binary'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Editor read error: '.$e->getMessage());
        }
    }

    public function saveEditor(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        if (strlen($this->editingContent) > 1024 * 1024) {
            session()->flash('error', 'File size exceeds 1 MB limit.');

            return;
        }

        try {
            $response = $client->execute(
                $this->account->server,
                'fs.write_text',
                [
                    'username' => $this->account->linux_username,
                    'path' => $this->editingFilePath,
                    'content' => $this->editingContent,
                ]
            );

            if ($response['success']) {
                $this->logAudit('fs.write_text', $this->editingFilePath, strlen($this->editingContent));
                session()->flash('status', "Saved '{$this->editingFileName}' successfully.");
                $this->isEditing = false;
                $this->loadDirectory($this->currentPath, $client);
                if ($this->editingFileName === '.htaccess' || str_ends_with($this->editingFilePath, '.htaccess')) {
                    $this->triggerHtaccessSync();
                }
            } else {
                session()->flash('error', 'Save failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Save error: '.$e->getMessage());
        }
    }

    // --- Download File ---

    public function downloadFile(string $path, AgentClient $client): StreamedResponse
    {
        $this->authorize('view', $this->account);
        $this->checkRateLimit();

        $response = $client->execute(
            $this->account->server,
            'file.read',
            [
                'username' => $this->account->linux_username,
                'path' => $path,
            ]
        );

        if (! $response['success']) {
            throw new \RuntimeException('Failed to read file for download: '.($response['error'] ?? 'Unknown error'));
        }

        $content = (string) ($response['data']['content'] ?? '');
        $filename = basename($path);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename);
    }

    // --- Chunked Upload (fs.upload_begin / fs.upload_chunk / fs.upload_commit) ---

    public function openUploadModal(): void
    {
        $this->uploads = [];
        $this->uploadErrors = [];
        $this->isUploading = true;
    }

    public function uploadFiles(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $maxMb = (int) config('hpanel.file_manager.max_upload_size_mb', 100);
        $maxKb = $maxMb * 1024;

        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['required', 'file', "max:{$maxKb}"],
        ]);

        $allowedExtensions = (array) config('hpanel.file_manager.allowed_extensions', []);

        foreach ($this->uploads as $file) {
            $origName = $file->getClientOriginalName();
            $lowerName = strtolower($origName);
            $ext = strtolower($file->getClientOriginalExtension());
            $isTarGz = str_ends_with($lowerName, '.tar.gz');

            if (! in_array($ext, $allowedExtensions, true) && ! ($isTarGz && in_array('tar.gz', $allowedExtensions, true))) {
                $this->addError('uploads', "File '{$origName}' has an invalid extension.");

                return;
            }
        }

        // Calculate quota remaining
        $quotaMb = (float) $this->account->package->storage_mb;
        $usedMb = (float) $this->account->current_storage_mb;
        $remainingQuotaBytes = (int) max(0, ($quotaMb - $usedMb) * 1024 * 1024);

        $successCount = 0;
        $this->uploadErrors = [];

        foreach ($this->uploads as $file) {
            $filename = $file->getClientOriginalName();
            $fileSize = (int) $file->getSize();

            // Filename validation
            try {
                $this->validateFilenameStrict($filename);
            } catch (\Throwable $e) {
                $this->uploadErrors[$filename] = $e->getMessage();
                continue;
            }

            if ($remainingQuotaBytes > 0 && $fileSize > $remainingQuotaBytes) {
                $this->uploadErrors[$filename] = 'File exceeds the remaining account storage quota.';
                continue;
            }

            try {
                // 1. fs.upload_begin
                $beginRes = $client->execute(
                    $this->account->server,
                    'fs.upload_begin',
                    [
                        'username' => $this->account->linux_username,
                        'destination_dir' => $this->currentPath,
                        'filename' => $filename,
                        'total_size' => $fileSize,
                        'quota_remaining' => $remainingQuotaBytes,
                    ]
                );

                if (! $beginRes['success']) {
                    $this->uploadErrors[$filename] = $beginRes['error'] ?? 'Upload initialization failed.';
                    continue;
                }

                $sessionId = (string) $beginRes['data']['session_id'];

                // 2. Stream chunks (4MB each)
                $chunkSize = 4 * 1024 * 1024;
                $handle = fopen($file->getRealPath(), 'rb');
                $chunkIndex = 0;
                $chunkFailed = false;

                while (! feof($handle)) {
                    $chunkData = fread($handle, $chunkSize);
                    if ($chunkData === false || strlen($chunkData) === 0) {
                        break;
                    }

                    $chunkRes = $client->execute(
                        $this->account->server,
                        'fs.upload_chunk',
                        [
                            'username' => $this->account->linux_username,
                            'session_id' => $sessionId,
                            'chunk_index' => $chunkIndex,
                            'content_base64' => base64_encode($chunkData),
                        ]
                    );

                    if (! $chunkRes['success']) {
                        $this->uploadErrors[$filename] = 'Chunk transfer failed: '.($chunkRes['error'] ?? '');
                        $chunkFailed = true;
                        break;
                    }

                    $chunkIndex++;
                }
                fclose($handle);

                if ($chunkFailed) {
                    continue;
                }

                // 3. fs.upload_commit
                $commitRes = $client->execute(
                    $this->account->server,
                    'fs.upload_commit',
                    [
                        'username' => $this->account->linux_username,
                        'session_id' => $sessionId,
                    ]
                );

                if ($commitRes['success']) {
                    $this->logAudit('fs.upload', $this->currentPath.'/'.$filename, $fileSize);
                    $successCount++;
                    $remainingQuotaBytes -= $fileSize;
                } else {
                    $this->uploadErrors[$filename] = 'Commit failed: '.($commitRes['error'] ?? '');
                }
            } catch (\Throwable $e) {
                $this->uploadErrors[$filename] = 'Upload error: '.$e->getMessage();
            }
        }

        $this->uploads = [];

        if ($successCount > 0) {
            session()->flash('status', "Uploaded {$successCount} file(s) successfully.");
            $this->loadDirectory($this->currentPath, $client);
            $this->triggerHtaccessSync();
        }

        if (empty($this->uploadErrors)) {
            $this->isUploading = false;
        }
    }

    // --- Archive & Extract ---

    public function openArchiveModal(): void
    {
        if (empty($this->selectedItems)) {
            return;
        }
        $this->archiveName = 'archive.zip';
        $this->isArchiving = true;
    }

    public function createArchive(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validateFilenameStrict($this->archiveName);

        $dest = $this->currentPath !== '' ? $this->currentPath.'/'.$this->archiveName : $this->archiveName;

        try {
            $job = AgentJob::create([
                'server_id' => $this->account->server_id,
                'account_id' => $this->account->id,
                'dispatched_by' => auth()->id(),
                'action' => 'file.archive',
                'status' => AgentJobStatus::RUNNING,
                'payload' => [
                    'username' => $this->account->linux_username,
                    'source_paths' => $this->selectedItems,
                    'destination_path' => $dest,
                    'format' => 'zip',
                ],
            ]);

            $response = $client->execute(
                $this->account->server,
                'file.archive',
                $job->payload
            );

            if ($response['success']) {
                $job->update(['status' => AgentJobStatus::COMPLETED]);
                $this->logAudit('fs.archive', $dest, 0);
                session()->flash('status', "Archive created: {$this->archiveName}");
                $this->isArchiving = false;
                $this->selectedItems = [];
                $this->loadDirectory($this->currentPath, $client);
            } else {
                $job->update(['status' => AgentJobStatus::FAILED, 'error_message' => $response['error'] ?? 'Failed']);
                session()->flash('error', 'Archive failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Archive error: '.$e->getMessage());
        }
    }

    public function openExtractModal(string $path): void
    {
        $this->extractArchivePath = $path;
        $this->extractDestinationDir = $this->currentPath ?: 'public_html';
        $this->isExtracting = true;
    }

    public function extractArchive(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);
        $this->checkRateLimit();

        $this->validate([
            'extractDestinationDir' => ['required', 'string', 'max:512'],
        ]);

        try {
            $job = AgentJob::create([
                'server_id' => $this->account->server_id,
                'account_id' => $this->account->id,
                'dispatched_by' => auth()->id(),
                'action' => 'file.extract',
                'status' => AgentJobStatus::RUNNING,
                'payload' => [
                    'username' => $this->account->linux_username,
                    'archive_path' => $this->extractArchivePath,
                    'destination_path' => $this->extractDestinationDir,
                ],
            ]);

            $response = $client->execute(
                $this->account->server,
                'file.extract',
                $job->payload
            );

            if ($response['success']) {
                $job->update(['status' => AgentJobStatus::COMPLETED]);
                $this->logAudit('fs.extract', $this->extractArchivePath.' -> '.$this->extractDestinationDir, 0);
                session()->flash('status', 'Extracted successfully.');
                $this->isExtracting = false;
                $this->loadDirectory($this->currentPath, $client);
                $this->loadFolderTree($client);
            } else {
                $job->update(['status' => AgentJobStatus::FAILED, 'error_message' => $response['error'] ?? 'Failed']);
                session()->flash('error', 'Extract failed: '.($response['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Extract error: '.$e->getMessage());
        }
    }

    protected function validateFilenameStrict(string $filename): void
    {
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new \InvalidArgumentException('Filename cannot be empty or relative traversal.');
        }

        // Reject control characters (< 32 or 127) and path separators
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) || str_contains($filename, '..')) {
            throw new \InvalidArgumentException('Filename contains invalid control characters or path separators.');
        }
    }

    protected function logAudit(string $action, string $path, int $size): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'target_type' => Account::class,
            'target_id' => $this->account->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old_values' => null,
            'new_values' => [
                'path' => $path,
                'size' => $size,
            ],
            'created_at' => now(),
        ]);
    }

    protected function triggerHtaccessSync(): void
    {
        $this->account->loadMissing('domains');
        foreach ($this->account->domains as $domain) {
            \App\Jobs\SyncHtaccessRulesJob::dispatch($domain);
        }
    }

    public function render(): View
    {
        $filtered = array_filter($this->entries, function ($entry) {
            if ($this->searchQuery === '') {
                return true;
            }

            return str_contains(strtolower((string) $entry['name']), strtolower($this->searchQuery));
        });

        // Sorting
        usort($filtered, function ($a, $b) {
            $dirA = ($a['type'] ?? '') === 'directory';
            $dirB = ($b['type'] ?? '') === 'directory';
            if ($dirA !== $dirB) {
                return $dirA ? -1 : 1;
            }

            $valA = $a[$this->sortBy] ?? '';
            $valB = $b[$this->sortBy] ?? '';

            if ($this->sortBy === 'size') {
                $cmp = ($valA <=> $valB);
            } else {
                $cmp = strcasecmp((string) $valA, (string) $valB);
            }

            return $this->sortDirection === 'asc' ? $cmp : -$cmp;
        });

        return view('livewire.files.online-sftp', [
            'displayEntries' => $filtered,
        ]);
    }
}
