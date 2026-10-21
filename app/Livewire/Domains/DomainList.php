<?php

namespace App\Livewire\Domains;

use App\Jobs\RemoveVhostJob;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class DomainList extends Component
{
    public string $search = '';

    public string $typeFilter = '';

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

        return view('livewire.domains.domain-list', [
            'domains' => $domains,
        ]);
    }
}
