<?php

namespace App\Livewire\Accounts;

use App\Enums\AccountStatus;
use App\Enums\AgentJobStatus;
use App\Jobs\ProvisionAccount;
use App\Jobs\SuspendAccount;
use App\Jobs\TerminateAccount;
use App\Jobs\UnsuspendAccount;
use App\Jobs\UpgradeAccount;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class AccountDetail extends Component
{
    public Account $account;

    public string $suspendReason = '';

    public bool $showingSuspendModal = false;

    public bool $showingUpgradeModal = false;

    public ?int $newPackageId = null;

    public bool $showingTerminateModal = false;

    public string $terminateConfirmation = '';

    public bool $terminateWithBackup = true;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['user', 'package', 'server']);
        $this->newPackageId = $account->package_id;
    }

    public function suspendAccount(): void
    {
        $this->authorize('update', $this->account);

        $this->validate([
            'suspendReason' => ['required', 'string', 'max:255'],
        ]);

        SuspendAccount::dispatch($this->account, $this->suspendReason);

        $this->showingSuspendModal = false;
        $this->suspendReason = '';

        session()->flash('status', "Suspension job for {$this->account->linux_username} has been queued.");
    }

    public function unsuspendAccount(): void
    {
        $this->authorize('update', $this->account);

        UnsuspendAccount::dispatch($this->account);

        session()->flash('status', "Unsuspension job for {$this->account->linux_username} has been queued.");
    }

    public function retryProvisioning(): void
    {
        $this->authorize('update', $this->account);

        if ($this->account->status !== AccountStatus::FAILED) {
            return;
        }

        $this->account->transitionTo(AccountStatus::PENDING);
        ProvisionAccount::dispatch($this->account);

        session()->flash('status', "Provisioning retry job enqueued for {$this->account->linux_username}.");
    }

    public function upgradePackage(): void
    {
        $this->authorize('update', $this->account);

        $this->validate([
            'newPackageId' => ['required', 'integer', 'exists:packages,id'],
        ]);

        $newPackage = Package::findOrFail($this->newPackageId);

        UpgradeAccount::dispatch($this->account, $newPackage);

        $this->showingUpgradeModal = false;
        session()->flash('status', "Upgrade job to package '{$newPackage->name}' enqueued.");
    }

    public function terminateAccount(): void
    {
        $this->authorize('delete', $this->account);

        $expectedToken = 'TERMINATE-'.$this->account->linux_username;
        if ($this->terminateConfirmation !== $expectedToken) {
            $this->addError('terminateConfirmation', "Please type exactly '{$expectedToken}' to confirm termination.");

            return;
        }

        TerminateAccount::dispatch($this->account, $this->terminateWithBackup);

        $this->showingTerminateModal = false;
        session()->flash('status', "Termination job enqueued for {$this->account->linux_username}.");
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $this->authorize('view', $this->account);

        $this->account->refresh();

        $recentLogs = AuditLog::where('target_type', Account::class)
            ->where('target_id', $this->account->id)
            ->latest('id')
            ->take(8)
            ->get();

        $recentAgentJobs = $this->account->agentJobs()->with('logs')->take(5)->get();
        $latestJob = $this->account->agentJobs()->with(['logs', 'provisioningSteps'])->first();
        $isProvisioning = $this->account->isPending() || ($latestJob && $latestJob->status === AgentJobStatus::RUNNING);
        $availablePackages = Package::availableFor($user)->get();

        return view('livewire.accounts.account-detail', [
            'recentLogs' => $recentLogs,
            'recentAgentJobs' => $recentAgentJobs,
            'latestJob' => $latestJob,
            'isProvisioning' => $isProvisioning,
            'availablePackages' => $availablePackages,
            'canManage' => $user->can('update', $this->account),
            'canDelete' => $user->can('delete', $this->account),
        ]);
    }
}
