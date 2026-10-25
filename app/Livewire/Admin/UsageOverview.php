<?php

namespace App\Livewire\Admin;

use App\Models\Account;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class UsageOverview extends Component
{
    public string $filter = 'all';

    public function mount(): void
    {
        $this->authorize('create', Account::class);
    }

    public function collectAll(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        Artisan::call('usage:collect');

        session()->flash('success', 'Dispatched background usage polling for all active accounts.');
    }

    public function toggleThrottle(int $accountId): void
    {
        /** @var Account $account */
        $account = Account::findOrFail($accountId);
        $this->authorize('update', $account);

        $account->update(['is_throttled' => ! $account->is_throttled]);

        session()->flash('success', "Throttling status updated for {$account->linux_username}.");
    }

    public function render(): View
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = Account::with(['package', 'server', 'user'])
            ->visibleTo($user)
            ->latest();

        $accounts = $query->get();

        if ($this->filter === 'exceeded') {
            $accounts = $accounts->filter(fn (Account $a) => $a->highestUsagePercentage() >= 100 || $a->is_throttled);
        } elseif ($this->filter === 'critical') {
            $accounts = $accounts->filter(fn (Account $a) => $a->highestUsagePercentage() >= 90 && $a->highestUsagePercentage() < 100);
        } elseif ($this->filter === 'warning') {
            $accounts = $accounts->filter(fn (Account $a) => $a->highestUsagePercentage() >= 80 && $a->highestUsagePercentage() < 90);
        }

        return view('livewire.admin.usage-overview', [
            'accounts' => $accounts,
        ]);
    }
}
