<?php

namespace App\Livewire\Accounts;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class AccountList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public ?int $serverId = null;

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

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $this->authorize('viewAny', Account::class);

        $accounts = Account::with(['user', 'package', 'server'])
            ->visibleTo($user)
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('primary_domain', 'like', '%'.$this->search.'%')
                        ->orWhere('linux_username', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', function (Builder $uq): void {
                            $uq->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('email', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->status !== '', function (Builder $query): void {
                $query->where('status', $this->status);
            })
            ->when($this->serverId, function (Builder $query): void {
                $query->where('server_id', $this->serverId);
            })
            ->latest('id')
            ->paginate(15);

        $servers = Server::orderBy('name')->get();
        $statuses = AccountStatus::cases();

        return view('livewire.accounts.account-list', [
            'accounts' => $accounts,
            'servers' => $servers,
            'statuses' => $statuses,
            'canCreate' => $user->can('create', Account::class),
        ]);
    }
}
