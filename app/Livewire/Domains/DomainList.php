<?php

namespace App\Livewire\Domains;

use App\Enums\AppProfile;
use App\Jobs\RemoveVhostJob;
use App\Jobs\WriteVhostJob;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class DomainList extends Component
{
    public string $search = '';

    public string $typeFilter = '';

    public bool $showingProfileModal = false;

    public ?int $editingDomainId = null;

    public string $selectedProfile = 'php-generic';

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

    public function deleteDomain(int $id): void
    {
        /** @var Domain $domain */
        $domain = Domain::with('account.server')->findOrFail($id);
        $this->authorize('delete', $domain);

        if ($domain->type->value === 'primary') {
            session()->flash('error', 'The primary domain of an account cannot be deleted. Terminate the account instead.');

            return;
        }

        RemoveVhostJob::dispatch($domain->account->server, $domain->account, $domain->domain);
        $domain->delete();

        session()->flash('status', "Domain '{$domain->domain}' removed and virtual host purged.");
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
            ->orderBy('domain')
            ->get();

        $editingDomain = $this->editingDomainId ? Domain::find($this->editingDomainId) : null;

        return view('livewire.domains.domain-list', [
            'domains' => $domains,
            'editingDomain' => $editingDomain,
        ]);
    }
}
