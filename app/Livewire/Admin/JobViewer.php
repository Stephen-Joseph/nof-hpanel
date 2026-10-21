<?php

namespace App\Livewire\Admin;

use App\Enums\AgentJobStatus;
use App\Jobs\ExecuteAgentAction;
use App\Models\AgentJob;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class JobViewer extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $serverId = '';

    public ?string $selectedJobId = null;

    public bool $autoRefresh = false;

    /**
     * Reset pagination when search filter changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingServerId(): void
    {
        $this->resetPage();
    }

    public function viewJob(string $id): void
    {
        $this->selectedJobId = $id;
    }

    public function closeModal(): void
    {
        $this->selectedJobId = null;
    }

    public function retryJob(string $id): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $job = AgentJob::findOrFail($id);

        $job->update([
            'status' => AgentJobStatus::PENDING,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $job->recordLog('Job retry manually triggered by administrator', 'info');

        ExecuteAgentAction::dispatch($job);

        session()->flash('status', "Job {$job->id} has been requeued.");
    }

    public function dispatchPing(int $serverId): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $server = Server::findOrFail($serverId);

        $job = AgentJob::create([
            'server_id' => $server->id,
            'dispatched_by' => Auth::id(),
            'action' => 'system.ping',
            'status' => AgentJobStatus::PENDING,
            'payload' => [],
            'dry_run' => false,
        ]);

        $job->recordLog('Manual ping initiated from admin dashboard', 'info');

        ExecuteAgentAction::dispatch($job);

        $this->selectedJobId = $job->id;
        session()->flash('status', "Ping action dispatched to server {$server->name}.");
    }

    public function render(): View
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $servers = Server::orderBy('name')->get(['id', 'name', 'hostname']);

        $jobs = AgentJob::with(['server', 'dispatcher'])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('action', 'like', '%'.$this->search.'%')
                        ->orWhere('id', 'like', '%'.$this->search.'%')
                        ->orWhereHas('server', function (Builder $serverQuery): void {
                            $serverQuery->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('hostname', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->status !== '', function (Builder $query): void {
                $query->where('status', $this->status);
            })
            ->when($this->serverId !== '', function (Builder $query): void {
                $query->where('server_id', (int) $this->serverId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $selectedJob = null;
        if ($this->selectedJobId !== null) {
            $selectedJob = AgentJob::with(['server', 'account', 'dispatcher', 'logs'])
                ->find($this->selectedJobId);
        }

        return view('livewire.admin.job-viewer', [
            'jobs' => $jobs,
            'servers' => $servers,
            'selectedJob' => $selectedJob,
        ]);
    }
}
