<?php

namespace App\Livewire\Accounts;

use App\Jobs\CollectAccountUsageJob;
use App\Models\Account;
use Illuminate\View\View;
use Livewire\Component;

class UsageDashboard extends Component
{
    public Account $account;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'domains', 'databases', 'usageSnapshots']);
    }

    public function pollNow(): void
    {
        $this->authorize('view', $this->account);

        CollectAccountUsageJob::dispatch($this->account);

        session()->flash('success', 'Real-time usage collection queued. Refreshing metrics...');
        $this->account->load(['usageSnapshots', 'domains', 'databases']);
    }

    public function render(): View
    {
        $recentSnapshots = $this->account->usageSnapshots()
            ->latest('recorded_at')
            ->take(15)
            ->get();

        return view('livewire.accounts.usage-dashboard', [
            'recentSnapshots' => $recentSnapshots,
        ]);
    }
}
