<?php

namespace App\Livewire\ApiIntegration;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class TokenManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public ?int $selectedTokenId = null;

    // For Edit Scopes modal
    public array $editingScopes = [];

    // For Edit IP Allowlist modal
    public string $editingIpAllowlist = '';

    // For Rotated Token display modal
    public ?string $rotatedPlainTextToken = null;

    /**
     * @var array<string, string>
     */
    public array $availableScopes = [
        'accounts:read' => 'Read account details, status and settings',
        'accounts:write' => 'Create, suspend, unsuspend, upgrade, terminate accounts and passwords',
        'domains:write' => 'Create and modify addon domains & subdomains',
        'ssl:write' => 'Issue and deploy SSL/TLS certificates',
        'usage:read' => 'View disk, bandwidth, and quota consumption',
        'health:read' => 'Inspect cluster and node health status',
        'packages:read' => 'View available hosting plans and quota specs',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function findScopedToken(int $id): ApiToken
    {
        /** @var User $user */
        $user = Auth::user();

        $query = ApiToken::where('id', $id);
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query->firstOrFail();
    }

    public function openEditScopes(int $id): void
    {
        $token = $this->findScopedToken($id);
        $this->selectedTokenId = $token->id;
        $this->editingScopes = $token->scopes ?? [];
        $this->dispatch('open-modal', 'edit-scopes-modal');
    }

    public function saveScopes(): void
    {
        if (! $this->selectedTokenId) {
            return;
        }

        $token = $this->findScopedToken($this->selectedTokenId);
        $token->update([
            'scopes' => array_values(array_filter($this->editingScopes)),
        ]);

        $this->dispatch('close-modal', 'edit-scopes-modal');
        $this->selectedTokenId = null;
        session()->flash('status', "Scopes updated for token '{$token->name}'.");
    }

    public function openEditIps(int $id): void
    {
        $token = $this->findScopedToken($id);
        $this->selectedTokenId = $token->id;
        $this->editingIpAllowlist = implode("\n", $token->ip_allowlist ?? []);
        $this->dispatch('open-modal', 'edit-ips-modal');
    }

    public function saveIps(): void
    {
        if (! $this->selectedTokenId) {
            return;
        }

        $token = $this->findScopedToken($this->selectedTokenId);

        $lines = preg_split('/\r\n|\r|\n/', $this->editingIpAllowlist);
        $ips = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                // Validate IP or CIDR
                if (! filter_var($trimmed, FILTER_VALIDATE_IP) && ! preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $trimmed)) {
                    $this->addError('editingIpAllowlist', "Invalid IP or CIDR address: {$trimmed}");

                    return;
                }
                $ips[] = $trimmed;
            }
        }

        $token->update([
            'ip_allowlist' => empty($ips) ? null : array_values(array_unique($ips)),
        ]);

        $this->dispatch('close-modal', 'edit-ips-modal');
        $this->selectedTokenId = null;
        session()->flash('status', "IP allow-list updated for token '{$token->name}'.");
    }

    public function confirmRevoke(int $id): void
    {
        $token = $this->findScopedToken($id);
        $this->selectedTokenId = $token->id;
        $this->dispatch('open-modal', 'revoke-token-modal');
    }

    public function revokeToken(): void
    {
        if (! $this->selectedTokenId) {
            return;
        }

        $token = $this->findScopedToken($this->selectedTokenId);
        $token->revoke();

        $this->dispatch('close-modal', 'revoke-token-modal');
        $this->selectedTokenId = null;
        session()->flash('status', "Token '{$token->name}' revoked.");
    }

    public function rotateToken(int $id): void
    {
        $token = $this->findScopedToken($id);
        $rotated = $token->rotate();

        $this->rotatedPlainTextToken = $rotated['plainTextToken'];
        $this->dispatch('open-modal', 'rotated-token-modal');
        session()->flash('status', "Token '{$token->name}' rotated successfully.");
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $query = ApiToken::with('user')->orderBy('id', 'desc');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($this->search !== '') {
            $query->where('name', 'like', "%{$this->search}%");
        }

        if ($this->statusFilter === 'active') {
            $query->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } elseif ($this->statusFilter === 'revoked') {
            $query->whereNotNull('revoked_at');
        } elseif ($this->statusFilter === 'expired') {
            $query->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        /** @var LengthAwarePaginator $tokens */
        $tokens = $query->paginate(10);

        return view('livewire.api-integration.token-manager', [
            'tokens' => $tokens,
        ])->layout('components.layouts.app');
    }
}

