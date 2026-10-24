<?php

namespace App\Livewire\ApiIntegration;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ApiOverview extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        // 1. Active tokens count
        $tokensQuery = ApiToken::query()
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if (! $user->isAdmin()) {
            $tokensQuery->where('user_id', $user->id);
        }
        $activeTokensCount = $tokensQuery->count();

        // 2. Requests 24h & Failed 24h
        $requestsQuery = ApiRequestLog::where('created_at', '>=', now()->subDay());
        if (! $user->isAdmin()) {
            $requestsQuery->where('user_id', $user->id);
        }
        $totalRequests24h = (clone $requestsQuery)->count();
        $failedRequests24h = (clone $requestsQuery)->where('status_code', '>=', 400)->count();

        // 3. Failed webhook deliveries
        $webhooksQuery = WebhookDelivery::where(function ($q) {
            $q->where('status_code', '>=', 400)
                ->orWhereNotNull('error');
        });
        if (! $user->isAdmin()) {
            $webhooksQuery->whereHas('subscription', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }
        $failedWebhooksCount = $webhooksQuery->count();

        // 4. Recent requests (last 10)
        $recentQuery = ApiRequestLog::with('token')->orderBy('id', 'desc')->take(10);
        if (! $user->isAdmin()) {
            $recentQuery->where('user_id', $user->id);
        }
        $recentRequests = $recentQuery->get();

        $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/').'/api/v1';

        return view('livewire.api-integration.api-overview', [
            'activeTokensCount' => $activeTokensCount,
            'totalRequests24h' => $totalRequests24h,
            'failedRequests24h' => $failedRequests24h,
            'failedWebhooksCount' => $failedWebhooksCount,
            'recentRequests' => $recentRequests,
            'baseUrl' => $baseUrl,
        ])->layout('components.layouts.app');
    }
}

