<?php

namespace App\Livewire\Domains;

use App\Enums\AppProfile;
use App\Jobs\IssueSslCertificateJob;
use App\Jobs\RemoveVhostJob;
use App\Jobs\WriteVhostJob;
use App\Models\Account;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainVerificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class DomainList extends Component
{
    public string $search = '';

    public string $typeFilter = '';

    public string $accountFilter = '';

    public bool $showingProfileModal = false;

    public ?int $editingDomainId = null;

    public string $selectedProfile = 'php-generic';

    public bool $showingHtaccessModal = false;

    public ?int $viewingHtaccessDomainId = null;

    public function openHtaccessModal(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.user'])->findOrFail($id);
        $this->authorize('view', $domain);

        $this->viewingHtaccessDomainId = $id;
        $this->showingHtaccessModal = true;
    }

    public function closeHtaccessModal(): void
    {
        $this->showingHtaccessModal = false;
        $this->viewingHtaccessDomainId = null;
    }

    public function rescanHtaccess(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.user'])->findOrFail($id);
        $this->authorize('update', $domain);

        dispatch_sync(new \App\Jobs\SyncHtaccessRulesJob($domain));

        session()->flash('status', ".htaccess rules for '{$domain->domain}' rescanned and converted.");
    }

    public function openProfileModal(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with('account.server')->findOrFail($id);
        $this->authorize('update', $domain);

        $this->editingDomainId = $id;
        $this->selectedProfile = $domain->app_profile->value;
        $this->showingProfileModal = true;
    }

    public function closeProfileModal(): void
    {
        $this->showingProfileModal = false;
        $this->editingDomainId = null;
        $this->resetErrorBag();
    }

    public function updateAppProfile(): void
    {
        if (! $this->editingDomainId) {
            return;
        }

        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.package', 'certificate', 'redirects', 'protectedDirectories'])
            ->findOrFail($this->editingDomainId);
        $this->authorize('update', $domain);

        $this->validate([
            'selectedProfile' => ['required', Rule::in(['php-generic', 'laravel', 'wordpress', 'static-spa'])],
        ]);

        $newProfile = AppProfile::from($this->selectedProfile);
        $domain->update(['app_profile' => $newProfile]);

        WriteVhostJob::dispatch($domain);

        $this->closeProfileModal();
        session()->flash('status', "Application profile for '{$domain->domain}' updated to '{$newProfile->label()}' and virtual host regenerated.");
    }

    public function changeAppProfile(int $id, string $profile): void
    {
        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.package', 'certificate', 'redirects', 'protectedDirectories'])
            ->findOrFail($id);
        $this->authorize('update', $domain);

        $validated = validator(['profile' => $profile], [
            'profile' => ['required', Rule::in(['php-generic', 'laravel', 'wordpress', 'static-spa'])],
        ])->validate();

        $newProfile = AppProfile::from($validated['profile']);
        $domain->update(['app_profile' => $newProfile]);

        WriteVhostJob::dispatch($domain);

        session()->flash('status', "Application profile for '{$domain->domain}' updated to '{$newProfile->label()}' and virtual host regenerated.");
    }

    public function deleteDomain(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with('account.server')->findOrFail($id);
        $this->authorize('view', $domain);

        if ($domain->is_primary || $domain->type->value === 'primary') {
            session()->flash('error', 'The primary domain of an account cannot be deleted. Terminate the account instead.');

            return;
        }

        $this->authorize('delete', $domain);

        RemoveVhostJob::dispatch($domain->account->server, $domain->account, $domain->domain);
        $domain->delete();

        session()->flash('status', "Domain '{$domain->domain}' removed and virtual host purged.");
    }

    public function verify(int $id, DomainVerificationService $service): void
    {
        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.user'])->findOrFail($id);
        $this->authorize('verify', $domain);

        $result = $service->verify($domain);

        if ($result->verified) {
            session()->flash('status', "Domain '{$domain->domain}' verified successfully.");
        } else {
            session()->flash('error', $result->message);
        }
    }

    public function enableSsl(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with(['account.server', 'account.user', 'certificate'])->findOrFail($id);
        $this->authorize('requestSsl', $domain);

        if ($domain->verification_status !== 'verified') {
            session()->flash('error', "Domain '{$domain->domain}' must be verified via DNS before requesting an SSL certificate.");

            return;
        }

        if ($domain->ssl_status === 'failed' && $domain->ssl_requested_at && $domain->ssl_requested_at->diffInMinutes(now()) < 60) {
            session()->flash('error', 'SSL validation failed recently for this domain. Please wait at least 1 hour between attempts.');

            return;
        }

        $email = $domain->account->user->email ?? 'admin@'.config('app.domain', 'hpanel.local');

        $domain->update([
            'ssl_requested_at' => now(),
            'ssl_status' => 'pending',
        ]);

        IssueSslCertificateJob::dispatch($domain, $email);

        session()->flash('status', "SSL certificate request initiated for {$domain->domain}.");
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $domains = Domain::with(['account.server', 'account.user'])
            ->whereHas('account', function (Builder $query) use ($user) {
                $query->visibleTo($user);
            })
            ->when($this->search !== '', function (Builder $query) {
                $query->where('domain', 'like', '%'.$this->search.'%');
            })
            ->when($this->typeFilter !== '', function (Builder $query) {
                $query->where('type', $this->typeFilter);
            })
            ->when($this->accountFilter !== '', function (Builder $query) {
                $query->where('account_id', (int) $this->accountFilter);
            })
            ->orderBy('domain')
            ->get();

        $accounts = ($user->isAdmin() || $user->isReseller())
            ? Account::visibleTo($user)->orderBy('linux_username')->get()
            : collect();

        $editingDomain = $this->editingDomainId ? Domain::find($this->editingDomainId) : null;
        $viewingHtaccessDomain = $this->viewingHtaccessDomainId ? Domain::with(['account.server'])->find($this->viewingHtaccessDomainId) : null;

        return view('livewire.domains.domain-list', [
            'domains' => $domains,
            'accounts' => $accounts,
            'editingDomain' => $editingDomain,
            'viewingHtaccessDomain' => $viewingHtaccessDomain,
        ]);
    }
}
