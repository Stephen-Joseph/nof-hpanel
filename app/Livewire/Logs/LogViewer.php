<?php

namespace App\Livewire\Logs;

use App\Models\Account;
use App\Services\AgentClient;
use Illuminate\View\View;
use Livewire\Component;

class LogViewer extends Component
{
    public Account $account;

    public string $selectedLog = '';

    public int $lines = 100;

    public string $search = '';

    public string $tab = 'all';

    public bool $autoRefresh = false;

    /** @var list<array{name: string, size_bytes: int, modified_at: string, category: string}> */
    public array $availableLogs = [];

    /** @var list<string> */
    public array $logContent = [];

    public int $fileSizeBytes = 0;

    public string $statusMessage = '';

    public function mount(Account $account, AgentClient $client): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['server']);

        $this->refreshLogList($client);

        if (! empty($this->availableLogs)) {
            $this->selectedLog = $this->availableLogs[0]['name'];
            $this->fetchLogLines($client);
        }
    }

    public function refreshLogList(AgentClient $client): void
    {
        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'log.list',
                payload: ['username' => $this->account->linux_username],
                dryRun: false
            );

            /** @var list<array{name: string, size_bytes: int, modified_at: string, category: string}> $files */
            $files = $response['data']['log_files'] ?? [];
            $this->availableLogs = $files;
        } catch (\Throwable $e) {
            $this->availableLogs = [
                ['name' => "{$this->account->primary_domain}.access.log", 'size_bytes' => 0, 'modified_at' => now()->toIso8601String(), 'category' => 'access'],
                ['name' => "{$this->account->primary_domain}.error.log", 'size_bytes' => 0, 'modified_at' => now()->toIso8601String(), 'category' => 'error'],
                ['name' => 'php_error.log', 'size_bytes' => 0, 'modified_at' => now()->toIso8601String(), 'category' => 'php'],
            ];
        }
    }

    public function selectLog(string $filename, AgentClient $client): void
    {
        $this->selectedLog = $filename;
        $this->fetchLogLines($client);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function updatedSearch(AgentClient $client): void
    {
        $this->fetchLogLines($client);
    }

    public function updatedLines(AgentClient $client): void
    {
        $this->fetchLogLines($client);
    }

    public function fetchLogLines(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        if (empty($this->selectedLog)) {
            return;
        }

        try {
            $response = $client->execute(
                server: $this->account->server,
                action: 'log.tail',
                payload: [
                    'username' => $this->account->linux_username,
                    'log_file' => $this->selectedLog,
                    'lines' => $this->lines,
                    'search' => $this->search,
                ],
                dryRun: false
            );

            /** @var list<string> $lines */
            $lines = $response['data']['lines'] ?? [];
            $this->logContent = $lines;
            $this->fileSizeBytes = (int) ($response['data']['file_size_bytes'] ?? 0);
            $this->statusMessage = '';
        } catch (\Throwable $e) {
            $this->logContent = [];
            $this->statusMessage = 'Could not load log: '.$e->getMessage();
        }
    }

    public function triggerRotate(AgentClient $client): void
    {
        $this->authorize('view', $this->account);

        try {
            $client->execute(
                server: $this->account->server,
                action: 'log.rotate',
                payload: [
                    'username' => $this->account->linux_username,
                    'force' => true,
                ],
                dryRun: false
            );

            session()->flash('status', 'Log rotation triggered successfully.');
            $this->refreshLogList($client);
            $this->fetchLogLines($client);
        } catch (\Throwable $e) {
            session()->flash('error', 'Log rotation failed: '.$e->getMessage());
        }
    }

    public function render(): View
    {
        $filteredLogs = collect($this->availableLogs)->filter(function ($item) {
            if ($this->tab === 'all') {
                return true;
            }
            if ($this->tab === 'web') {
                return in_array($item['category'], ['access', 'error'], true);
            }
            if ($this->tab === 'php') {
                return $item['category'] === 'php';
            }
            if ($this->tab === 'cron') {
                return $item['category'] === 'cron';
            }

            return true;
        })->values()->all();

        return view('livewire.logs.log-viewer', [
            'filteredLogs' => $filteredLogs,
        ]);
    }
}
