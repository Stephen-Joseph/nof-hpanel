<?php

namespace App\Livewire\Admin;

use App\Models\DeployRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class SystemUpdatesManager extends Component
{
    public bool $showingRollbackModal = false;

    public string $confirmTargetSha = '';

    public ?int $selectedRunId = null;

    public bool $showingLogModal = false;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    public function deployNow(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $originInfo = $this->getOriginStatus();
        $targetSha = $originInfo['remote_sha'] ?? 'origin/main';
        $commitMessage = $originInfo['commit_message'] ?? 'Manual deployment from panel';
        $hasMigrations = (bool) ($originInfo['has_migrations'] ?? false);

        $triggerPath = config('hpanel.deploy.trigger_path', '/var/lib/hpanel/deploy/trigger.json');
        $dir = dirname($triggerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $triggerData = [
            'action' => 'deploy',
            'commit_sha' => $targetSha,
            'commit_message' => $commitMessage,
            'author' => Auth::user()->name ?? 'Administrator',
            'delivery_id' => 'manual-ui-' . bin2hex(random_bytes(4)),
            'has_migrations' => $hasMigrations,
            'queued_at' => now()->toIso8601String(),
        ];

        $tmp = $triggerPath . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($tmp, json_encode($triggerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0644);
        rename($tmp, $triggerPath);

        // Store a deploy_runs row with status queued
        DeployRun::create([
            'commit_sha' => $targetSha,
            'commit_message' => $commitMessage,
            'author' => Auth::user()->name ?? 'Administrator',
            'delivery_id' => $triggerData['delivery_id'],
            'status' => 'queued',
            'action' => 'deploy',
            'has_migrations' => $hasMigrations,
        ]);

        session()->flash('success', 'Deployment trigger written. Systemd updater will execute the deployment in the background.');
    }

    public function openRollbackModal(string $targetSha = ''): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        if ($targetSha === '') {
            $releases = $this->getAvailableReleases();
            $targetSha = $releases[0]['commit'] ?? '';
        }

        $this->confirmTargetSha = $targetSha;
        $this->showingRollbackModal = true;
    }

    public function closeRollbackModal(): void
    {
        $this->showingRollbackModal = false;
        $this->confirmTargetSha = '';
    }

    public function executeRollback(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        if ($this->confirmTargetSha === '') {
            session()->flash('error', 'No target release selected for rollback.');
            $this->closeRollbackModal();
            return;
        }

        $targetSha = $this->confirmTargetSha;
        $triggerPath = config('hpanel.deploy.trigger_path', '/var/lib/hpanel/deploy/trigger.json');
        $dir = dirname($triggerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $triggerData = [
            'action' => 'rollback',
            'target_sha' => $targetSha,
            'commit_sha' => $targetSha,
            'author' => Auth::user()->name ?? 'Administrator',
            'delivery_id' => 'rollback-ui-' . bin2hex(random_bytes(4)),
            'has_migrations' => false,
            'queued_at' => now()->toIso8601String(),
        ];

        $tmp = $triggerPath . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($tmp, json_encode($triggerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0644);
        rename($tmp, $triggerPath);

        DeployRun::create([
            'commit_sha' => $targetSha,
            'commit_message' => "Rollback to {$targetSha}",
            'author' => Auth::user()->name ?? 'Administrator',
            'delivery_id' => $triggerData['delivery_id'],
            'status' => 'queued',
            'action' => 'rollback',
            'has_migrations' => false,
        ]);

        $this->closeRollbackModal();
        session()->flash('success', "Rollback trigger written for release {$targetSha}. System updater will restore previous code and agent binary.");
    }

    public function openLogModal(int $runId): void
    {
        $this->selectedRunId = $runId;
        $this->showingLogModal = true;
    }

    public function closeLogModal(): void
    {
        $this->showingLogModal = false;
        $this->selectedRunId = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentRelease(): array
    {
        $path = config('hpanel.deploy.current_release_path', '/var/lib/hpanel/deploy/current_release.json');
        if (file_exists($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Fallback to latest successful DeployRun
        $latestSuccess = DeployRun::where('status', 'success')->latest()->first();
        if ($latestSuccess) {
            return [
                'commit' => $latestSuccess->commit_sha,
                'message' => $latestSuccess->commit_message ?? 'Current release',
                'author' => $latestSuccess->author ?? 'System',
                'deployed_at' => $latestSuccess->finished_at?->toIso8601String() ?? $latestSuccess->created_at->toIso8601String(),
                'status' => 'success',
            ];
        }

        // File-based git HEAD read (no process execution)
        $headFile = base_path('.git/HEAD');
        $sha = 'unknown';
        if (file_exists($headFile)) {
            $headContent = trim((string) file_get_contents($headFile));
            if (str_starts_with($headContent, 'ref: ')) {
                $refPath = base_path('.git/' . substr($headContent, 5));
                if (file_exists($refPath)) {
                    $sha = trim((string) file_get_contents($refPath));
                }
            } else {
                $sha = $headContent;
            }
        }

        return [
            'commit' => $sha,
            'message' => 'Active workspace release',
            'author' => 'System',
            'deployed_at' => null,
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOriginStatus(): array
    {
        $path = config('hpanel.deploy.origin_status_path', '/var/lib/hpanel/deploy/origin_status.json');
        if (file_exists($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Fallback to latest queued run if any
        $latestQueued = DeployRun::where('status', 'queued')->latest()->first();
        if ($latestQueued) {
            return [
                'remote_sha' => $latestQueued->commit_sha,
                'commit_message' => $latestQueued->commit_message ?? 'Queued deployment',
                'author' => $latestQueued->author ?? 'GitHub',
                'up_to_date' => false,
                'has_migrations' => $latestQueued->has_migrations,
                'last_checked_at' => $latestQueued->created_at->toIso8601String(),
            ];
        }

        return [
            'remote_sha' => null,
            'commit_message' => null,
            'author' => null,
            'up_to_date' => true,
            'has_migrations' => false,
            'last_checked_at' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableReleases(): array
    {
        $releasesDir = config('hpanel.deploy.releases_path', '/var/lib/hpanel/releases');
        $releases = [];

        if (is_dir($releasesDir)) {
            $dirs = glob($releasesDir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $sha = basename($dir);
                $commitFile = $dir . '/commit';
                $msgFile = $dir . '/message';
                $createdAtFile = $dir . '/created_at';
                $hasAgent = file_exists($dir . '/panel-agent');

                $commit = file_exists($commitFile) ? trim((string) file_get_contents($commitFile)) : $sha;
                $msg = file_exists($msgFile) ? trim((string) file_get_contents($msgFile)) : 'Release snapshot';
                $date = file_exists($createdAtFile) ? trim((string) file_get_contents($createdAtFile)) : null;

                $releases[] = [
                    'commit' => $commit,
                    'short_commit' => substr($commit, 0, 7),
                    'message' => $msg,
                    'created_at' => $date ? Carbon::parse($date)->diffForHumans() : 'Recently',
                    'has_agent_snapshot' => $hasAgent,
                    'mtime' => filemtime($dir),
                ];
            }

            usort($releases, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        }

        // If releases dir is empty, fallback to previous successful deploy runs
        if (empty($releases)) {
            $prevRuns = DeployRun::where('status', 'success')
                ->latest()
                ->take(5)
                ->get();

            foreach ($prevRuns as $run) {
                $releases[] = [
                    'commit' => $run->commit_sha,
                    'short_commit' => substr($run->commit_sha, 0, 7),
                    'message' => $run->commit_message ?? 'Previous release',
                    'created_at' => $run->created_at->diffForHumans(),
                    'has_agent_snapshot' => true,
                    'mtime' => $run->created_at->timestamp,
                ];
            }
        }

        return array_slice($releases, 0, 5);
    }

    public function render(): View
    {
        $currentRelease = $this->getCurrentRelease();
        $originStatus = $this->getOriginStatus();
        $availableReleases = $this->getAvailableReleases();
        $latestRun = DeployRun::latest()->first();
        $history = DeployRun::latest()->paginate(15);

        $selectedRun = null;
        if ($this->selectedRunId) {
            $selectedRun = DeployRun::find($this->selectedRunId);
        }

        return view('livewire.admin.system-updates-manager', [
            'currentRelease' => $currentRelease,
            'originStatus' => $originStatus,
            'availableReleases' => $availableReleases,
            'latestRun' => $latestRun,
            'history' => $history,
            'selectedRun' => $selectedRun,
            'repoUrl' => config('hpanel.deploy.repo_url', 'https://github.com/Stephen-Joseph/nof-hpanel.git'),
        ])->layout('components.layouts.app', [
            'title' => 'System Updates &middot; ' . config('app.name', 'HPanel'),
        ]);
    }
}

