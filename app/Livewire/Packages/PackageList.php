<?php

namespace App\Livewire\Packages;

use App\Models\Package;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class PackageList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function deletePackage(int $id, AuditService $audit): void
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Package|null $package */
        $package = Package::find($id);

        if (! $package) {
            return;
        }

        $this->authorize('delete', $package);

        if ($package->accounts()->exists()) {
            session()->flash('error', 'Cannot delete package because it has assigned accounts.');

            return;
        }

        $audit->log('package.deleted', $package, $package->toArray(), null, $user);
        $package->delete();

        session()->flash('status', 'Package successfully deleted.');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $this->authorize('viewAny', Package::class);

        $packages = Package::with(['resellerOwner'])
            ->withCount('accounts')
            ->availableFor($user)
            ->when($this->search !== '', function (Builder $query): void {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->latest('id')
            ->paginate(10);

        return view('livewire.packages.package-list', [
            'packages' => $packages,
            'canCreate' => $user->can('create', Package::class),
        ]);
    }
}
