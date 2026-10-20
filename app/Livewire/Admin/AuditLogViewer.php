<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogViewer extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = '';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public ?int $selectedLogId = null;

    /**
     * Reset pagination when search filter changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->selectedLogId = $id;
    }

    public function closeDetails(): void
    {
        $this->selectedLogId = null;
    }

    public function render(): View
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $logs = AuditLog::with('user')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('action', 'like', '%'.$this->search.'%')
                        ->orWhere('ip_address', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', function (Builder $userQuery): void {
                            $userQuery->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('email', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->action !== '', function (Builder $query): void {
                $query->where('action', 'like', $this->action.'%');
            })
            ->when($this->dateFrom, function (Builder $query): void {
                $query->where('created_at', '>=', $this->dateFrom.' 00:00:00');
            })
            ->when($this->dateTo, function (Builder $query): void {
                $query->where('created_at', '<=', $this->dateTo.' 23:59:59');
            })
            ->latest('id')
            ->paginate(15);

        $selectedLog = $this->selectedLogId
            ? AuditLog::with('user')->find($this->selectedLogId)
            : null;

        return view('livewire.admin.audit-log-viewer', [
            'logs' => $logs,
            'selectedLog' => $selectedLog,
        ]);
    }
}
