<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ServerList extends Component
{
    public function deleteServer(int $id, AuditService $audit): void
    {
        /** @var User $user */
        $user = Auth::user();
        /** @var Server|null $server */
        $server = Server::find($id);

        if (! $server) {
            return;
        }

        $this->authorize('delete', $server);

        if ($server->accounts()->exists()) {
            session()->flash('error', 'Cannot remove server because active accounts are currently hosted on it.');

            return;
        }

        $audit->log('server.deleted', $server, $server->toArray(), null, $user);
        $server->delete();

        session()->flash('status', 'Server node successfully removed.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::withCount('accounts')
            ->orderBy('name')
            ->get();

        return view('livewire.servers.server-list', [
            'servers' => $servers,
        ]);
    }
}
