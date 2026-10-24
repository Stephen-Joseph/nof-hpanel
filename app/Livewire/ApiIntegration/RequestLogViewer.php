<?php

namespace App\Livewire\ApiIntegration;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestLogViewer extends Component
{
    use WithPagination;

    public string $tokenFilter = '';

    public string $methodFilter = '';

    public string $statusFilter = ''; // '2xx', '3xx', '4xx', '5xx'

    public string $dateRange = '7days'; // 'today', 'yesterday', '7days', '30days', 'all'

    public ?int $selectedLogId = null;

    public function updatingTokenFilter(): void
    {
        $this->resetPage();
    }

    public function updatingMethodFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateRange(): void
    {
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->selectedLogId = $id;
        $this->dispatch('open-modal', 'log-details-modal');
    }

    /**
     * @return Builder<ApiRequestLog>
     */
    protected function getFilteredQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        $query = ApiRequestLog::with(['token', 'user'])->orderBy('id', 'desc');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($this->tokenFilter !== '') {
            $query->where('api_token_id', (int) $this->tokenFilter);
        }

        if ($this->methodFilter !== '') {
            $query->where('method', strtoupper($this->methodFilter));
        }

        if ($this->statusFilter === '2xx') {
            $query->whereBetween('status_code', [200, 299]);
        } elseif ($this->statusFilter === '3xx') {
            $query->whereBetween('status_code', [300, 399]);
        } elseif ($this->statusFilter === '4xx') {
            $query->whereBetween('status_code', [400, 499]);
        } elseif ($this->statusFilter === '5xx') {
            $query->whereBetween('status_code', [500, 599]);
        }

        if ($this->dateRange === 'today') {
            $query->where('created_at', '>=', now()->startOfDay());
        } elseif ($this->dateRange === 'yesterday') {
            $query->whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()]);
        } elseif ($this->dateRange === '7days') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($this->dateRange === '30days') {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        return $query;
    }

    public function exportCsv(): StreamedResponse
    {
        $fileName = 'api-telemetry-'.now()->format('Y-m-d-His').'.csv';

        $query = $this->getFilteredQuery();

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Timestamp', 'Method', 'Path', 'Status', 'Duration (ms)', 'Token Prefix', 'IP Address', 'User Agent']);

            $query->chunk(200, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->created_at->toIso8601String(),
                        $log->method,
                        $log->path,
                        $log->status_code,
                        $log->duration_ms,
                        $log->token?->prefix ?? 'N/A',
                        $log->ip_address,
                        $log->user_agent,
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $tokensQuery = ApiToken::query()->orderBy('name');
        if (! $user->isAdmin()) {
            $tokensQuery->where('user_id', $user->id);
        }
        $availableTokens = $tokensQuery->get(['id', 'name', 'prefix']);

        /** @var LengthAwarePaginator $logs */
        $logs = $this->getFilteredQuery()->paginate(15);

        $selectedLog = $this->selectedLogId ? ApiRequestLog::with(['token', 'user'])->find($this->selectedLogId) : null;

        return view('livewire.api-integration.request-log-viewer', [
            'logs' => $logs,
            'availableTokens' => $availableTokens,
            'selectedLog' => $selectedLog,
        ])->layout('components.layouts.app');
    }
}

