<?php

namespace App\Livewire\Files;

use App\Enums\AgentJobStatus;
use App\Models\Account;
use App\Models\AgentJob;
use App\Services\AgentClient;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class FileManager extends Component
{
    use WithFileUploads;

    public Account $account;

    public string $currentPath = '';

    public ?string $parentPath = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $entries = [];

    // Editor modal state
    public bool $isEditing = false;

    public string $editingFilePath = '';

    public string $editingFileName = '';

    public string $editingContent = '';

    // Create item modal state
    public bool $isCreating = false;

    public string $newItemName = '';

    public bool $newItemIsDir = false;

    // Rename state
    public bool $isRenaming = false;

    public string $renamingTarget = '';

    public string $renamingNewName = '';

    // Permissions (chmod) state
    public bool $isChmodding = false;

    public string $chmodTarget = '';

    public string $chmodMode = '0644';

    // Upload state
    public bool $isUploading = false;

    /**
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $uploads = [];

    public string $uploadTargetFolder = 'public_html';

    // Extract state
    public bool $isExtracting = false;

    public string $extractArchivePath = '';

    public string $extractDestinationDir = '';

    // Archive state
    public bool $isArchiving = false;

    /**
     * @var array<int, string>
     */
    public array $selectedItems = [];

    /**
     * @var array<int, string>
     */
    public array $archiveSources = [];

    public string $archiveDestinationName = 'archive.zip';

    public function mount(Account $account, AgentClient $client): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['server', 'package']);
        $this->uploadTargetFolder = 'public_html';

        $this->loadDirectory('', $client);
    }

    public function loadDirectory(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.list',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $data = $response['data'];
                $this->currentPath = $data['rel_path'] ?? '';
                $this->parentPath = ! empty($data['parent_path']) ? $data['parent_path'] : null;
                $this->entries = $data['entries'] ?? [];
                $this->selectedItems = [];
            } else {
                session()->flash('error', $response['error'] ?? 'Failed reading directory.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'File Manager error: '.$e->getMessage());
        }
    }

    public function editFile(string $path, AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.read',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->editingFilePath = $path;
                $this->editingFileName = basename($path);
                $this->editingContent = $response['data']['content'] ?? '';
                $this->isEditing = true;
            } else {
                session()->flash('error', $response['error'] ?? 'Could not read file.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Read error: '.$e->getMessage());
        }
    }

    public function saveFile(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.write',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $this->editingFilePath,
                    'content' => $this->editingContent,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isEditing = false;
                session()->flash('status', "File '{$this->editingFileName}' saved successfully.");
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Failed saving file.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Save error: '.$e->getMessage());
        }
    }

    public function openCreateModal(bool $isDir = false): void
    {
        $this->newItemName = '';
        $this->newItemIsDir = $isDir;
        $this->isCreating = true;
    }

    public function createItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $this->validate([
            'newItemName' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/', 'max:128'],
        ]);

        $fullPath = $this->currentPath ? $this->currentPath.'/'.$this->newItemName : $this->newItemName;

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.create',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $fullPath,
                    'is_dir' => $this->newItemIsDir,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isCreating = false;
                $this->newItemName = '';
                session()->flash('status', ($this->newItemIsDir ? 'Directory' : 'File').' created successfully.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Creation failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Create error: '.$e->getMessage());
        }
    }

    public function deleteItem(string $path, AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.delete',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $path,
                ],
                dryRun: false
            );

            if ($response['success']) {
                session()->flash('status', 'Item deleted.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Delete failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Delete error: '.$e->getMessage());
        }
    }

    public function openRenameModal(string $path): void
    {
        $this->renamingTarget = $path;
        $this->renamingNewName = basename($path);
        $this->isRenaming = true;
    }

    public function renameItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $this->validate([
            'renamingNewName' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+$/', 'max:128'],
        ]);

        $targetDir = dirname($this->renamingTarget);
        $newPath = $targetDir.'/'.$this->renamingNewName;

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.rename',
                payload: [
                    'username' => $this->account->linux_username,
                    'source_path' => $this->renamingTarget,
                    'target_path' => $newPath,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isRenaming = false;
                session()->flash('status', 'Item renamed.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Rename failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Rename error: '.$e->getMessage());
        }
    }

    public function openChmodModal(string $path, string $currentMode): void
    {
        $this->chmodTarget = $path;
        $this->chmodMode = $currentMode;
        $this->isChmodding = true;
    }

    public function chmodItem(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $this->validate([
            'chmodMode' => ['required', 'regex:/^0?[0-7]{3}$/'],
        ]);

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.chmod',
                payload: [
                    'username' => $this->account->linux_username,
                    'path' => $this->chmodTarget,
                    'mode' => $this->chmodMode,
                ],
                dryRun: false
            );

            if ($response['success']) {
                $this->isChmodding = false;
                session()->flash('status', 'Permissions updated.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                session()->flash('error', $response['error'] ?? 'Chmod failed.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Chmod error: '.$e->getMessage());
        }
    }

    // --- Upload Actions ---

    public function openUploadModal(): void
    {
        $this->resetErrorBag();
        $this->uploads = [];
        $this->uploadTargetFolder = $this->currentPath ?: 'public_html';
        $this->isUploading = true;
    }

    public function uploadFiles(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $maxMb = (int) config('hpanel.file_manager.max_upload_size_mb', 100);
        $maxKb = $maxMb * 1024;

        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['required', 'file', "max:{$maxKb}"],
            'uploadTargetFolder' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            $targetFolder = $this->normalizePathInsideHome($this->uploadTargetFolder ?: 'public_html');
        } catch (\Throwable $e) {
            $this->addError('uploadTargetFolder', 'Target folder must be inside your home directory and cannot traverse outside.');

            return;
        }

        $allowed = (array) config('hpanel.file_manager.allowed_extensions', []);
        foreach ($this->uploads as $file) {
            $origName = $file->getClientOriginalName();
            $lowerName = strtolower($origName);
            $ext = strtolower($file->getClientOriginalExtension());
            $isTarGz = str_ends_with($lowerName, '.tar.gz');

            if (! in_array($ext, $allowed, true) && ! ($isTarGz && in_array('tar.gz', $allowed, true))) {
                $this->addError('uploads', "File '{$origName}' has an invalid extension.");

                return;
            }
        }

        $uploadedCount = 0;

        foreach ($this->uploads as $uploadedFile) {
            $uploadUuid = (string) Str::uuid();
            $tempDir = storage_path("app/private/uploads/{$uploadUuid}");
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $filename = $uploadedFile->getClientOriginalName();
            $tempFilePath = $tempDir.DIRECTORY_SEPARATOR.$filename;
            copy($uploadedFile->getRealPath(), $tempFilePath);

            $fileSize = (int) filesize($tempFilePath);
            $chunkSize = 1024 * 1024; // 1 MB chunks
            $totalChunks = max(1, (int) ceil($fileSize / $chunkSize));
            $uploadId = Str::random(32);

            $agentJob = AgentJob::create([
                'server_id' => $this->account->server_id,
                'account_id' => $this->account->id,
                'dispatched_by' => auth()->id(),
                'action' => 'file.upload',
                'status' => AgentJobStatus::RUNNING,
                'payload' => [
                    'username' => $this->account->linux_username,
                    'target_path' => $targetFolder,
                    'filename' => $filename,
                    'size' => $fileSize,
                    'chunks' => $totalChunks,
                ],
                'dry_run' => false,
                'attempts' => 1,
                'started_at' => now(),
            ]);

            $agentJob->recordLog("Uploading '{$filename}' ({$fileSize} bytes) into '{$targetFolder}'", 'info');

            $fh = fopen($tempFilePath, 'rb');
            try {
                for ($chunkIdx = 0; $chunkIdx < $totalChunks; $chunkIdx++) {
                    $chunkData = fread($fh, $chunkSize);
                    $response = $client->execute(
                        server: $this->account->server,
                        action: 'file.upload',
                        payload: [
                            'username' => $this->account->linux_username,
                            'target_path' => $targetFolder,
                            'filename' => $filename,
                            'content_base64' => base64_encode($chunkData),
                            'chunk_index' => $chunkIdx,
                            'total_chunks' => $totalChunks,
                            'upload_id' => $uploadId,
                            'max_size' => $maxMb * 1024 * 1024,
                        ],
                        dryRun: false,
                        requestId: (string) $agentJob->id
                    );

                    if (! ($response['success'] ?? false)) {
                        $err = $response['error'] ?? 'Agent failed to save uploaded file.';
                        throw new \RuntimeException($err);
                    }
                }
                fclose($fh);
                $agentJob->markCompleted($response['data'] ?? []);
                $uploadedCount++;
            } catch (\Throwable $e) {
                if (is_resource($fh)) {
                    fclose($fh);
                }
                $agentJob->recordLog("Upload failed: {$e->getMessage()}", 'error');
                $agentJob->markFailed($e->getMessage());
                @unlink($tempFilePath);
                @rmdir($tempDir);
                session()->flash('error', "Upload failed for {$filename}: {$e->getMessage()}");

                return;
            } finally {
                @unlink($tempFilePath);
                @rmdir($tempDir);
            }
        }

        $this->isUploading = false;
        $this->uploads = [];
        session()->flash('status', "{$uploadedCount} file(s) uploaded successfully.");
        $this->loadDirectory($this->currentPath, $client);
    }

    // --- Extract Actions ---

    public function extractHere(string $archivePath, AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $destDir = $this->currentPath ?: dirname($archivePath);
        $this->executeExtract($archivePath, $destDir, $client);
    }

    public function extractZip(string $zipPath, AgentClient $client): void
    {
        $this->extractHere($zipPath, $client);
    }

    public function openExtractModal(string $archivePath): void
    {
        $this->resetErrorBag();
        $this->extractArchivePath = $archivePath;
        $this->extractDestinationDir = $this->currentPath ?: 'public_html';
        $this->isExtracting = true;
    }

    public function extractToFolder(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $this->validate([
            'extractDestinationDir' => ['required', 'string', 'max:512'],
        ]);

        try {
            $destDir = $this->normalizePathInsideHome($this->extractDestinationDir);
        } catch (\Throwable $e) {
            $this->addError('extractDestinationDir', 'Destination directory must be inside your home directory and cannot traverse outside.');

            return;
        }

        $this->isExtracting = false;
        $this->executeExtract($this->extractArchivePath, $destDir, $client);
    }

    protected function executeExtract(string $archivePath, string $destinationDir, AgentClient $client): void
    {
        $agentJob = AgentJob::create([
            'server_id' => $this->account->server_id,
            'account_id' => $this->account->id,
            'dispatched_by' => auth()->id(),
            'action' => 'file.extract',
            'status' => AgentJobStatus::RUNNING,
            'payload' => [
                'username' => $this->account->linux_username,
                'archive_path' => $archivePath,
                'destination_dir' => $destinationDir,
            ],
            'dry_run' => false,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog("Extracting archive '{$archivePath}' into '{$destinationDir}'", 'info');

        try {
            $quotaMb = $this->account->package?->storage_mb ?? 10240;

            $response = $client->execute(
                server: $this->account->server,
                action: 'file.extract',
                payload: [
                    'username' => $this->account->linux_username,
                    'archive_path' => $archivePath,
                    'destination_dir' => $destinationDir,
                    'storage_quota_mb' => $quotaMb,
                ],
                dryRun: false,
                requestId: (string) $agentJob->id
            );

            foreach ($response['logs'] ?? [] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data'] ?? []);
                session()->flash('status', 'Archive extracted successfully.');
                $this->loadDirectory($this->currentPath, $client);
            } else {
                $errorMsg = $response['error'] ?? 'Extract failed.';
                $agentJob->recordLog("Extraction failed: {$errorMsg}", 'error');
                $agentJob->markFailed($errorMsg);
                session()->flash('error', $errorMsg);
            }
        } catch (\Throwable $e) {
            $agentJob->recordLog("Extraction error: {$e->getMessage()}", 'error');
            $agentJob->markFailed($e->getMessage());
            session()->flash('error', 'Extract error: '.$e->getMessage());
        }
    }

    // --- Archive Actions ---

    public function toggleSelectAll(): void
    {
        if (count($this->selectedItems) === count($this->entries)) {
            $this->selectedItems = [];
        } else {
            $this->selectedItems = array_values(array_map(fn ($e) => $e['path'], $this->entries));
        }
    }

    public function openArchiveModal(?string $singlePath = null): void
    {
        $this->resetErrorBag();

        if ($singlePath) {
            $this->archiveSources = [$singlePath];
            $base = basename($singlePath);
            $this->archiveDestinationName = ($base ?: 'archive').'.zip';
        } elseif (! empty($this->selectedItems)) {
            $this->archiveSources = $this->selectedItems;
            $this->archiveDestinationName = 'archive.zip';
        } else {
            session()->flash('error', 'Select at least one file or folder to archive.');

            return;
        }

        $this->isArchiving = true;
    }

    public function createArchive(AgentClient $client): void
    {
        $this->authorize('manageFiles', $this->account);

        $this->validate([
            'archiveDestinationName' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\-\.]+\.zip$/i', 'max:128'],
            'archiveSources' => ['required', 'array', 'min:1'],
            'archiveSources.*' => ['required', 'string'],
        ]);

        $destZip = $this->currentPath
            ? trim($this->currentPath, '/').'/'.$this->archiveDestinationName
            : $this->archiveDestinationName;

        $agentJob = AgentJob::create([
            'server_id' => $this->account->server_id,
            'account_id' => $this->account->id,
            'dispatched_by' => auth()->id(),
            'action' => 'file.archive',
            'status' => AgentJobStatus::RUNNING,
            'payload' => [
                'username' => $this->account->linux_username,
                'sources' => $this->archiveSources,
                'destination_zip' => $destZip,
            ],
            'dry_run' => false,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $agentJob->recordLog(
            sprintf("Archiving %d items into '%s'", count($this->archiveSources), $destZip),
            'info'
        );

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'file.archive',
                payload: [
                    'username' => $this->account->linux_username,
                    'sources' => $this->archiveSources,
                    'destination_zip' => $destZip,
                ],
                dryRun: false,
                requestId: (string) $agentJob->id
            );

            foreach ($response['logs'] ?? [] as $logLine) {
                $agentJob->recordLog($logLine, 'info');
            }

            if ($response['success']) {
                $agentJob->markCompleted($response['data'] ?? []);
                $this->isArchiving = false;
                $this->selectedItems = [];
                $this->archiveSources = [];
                session()->flash('status', "Archive '{$this->archiveDestinationName}' created successfully.");
                $this->loadDirectory($this->currentPath, $client);
            } else {
                $errorMsg = $response['error'] ?? 'Archive failed.';
                $agentJob->recordLog("Archive failed: {$errorMsg}", 'error');
                $agentJob->markFailed($errorMsg);
                session()->flash('error', $errorMsg);
            }
        } catch (\Throwable $e) {
            $agentJob->recordLog("Archive error: {$e->getMessage()}", 'error');
            $agentJob->markFailed($e->getMessage());
            session()->flash('error', 'Archive error: '.$e->getMessage());
        }
    }

    protected function normalizePathInsideHome(string $path): string
    {
        $username = $this->account->linux_username;
        $homePrefix = "/home/{$username}";

        $path = trim($path);
        if (str_starts_with($path, $homePrefix)) {
            $path = substr($path, strlen($homePrefix));
        }
        $path = trim($path, '/');

        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Invalid path: path traversal detected.');
        }

        return $path;
    }

    public function render(): View
    {
        return view('livewire.files.file-manager');
    }
}
