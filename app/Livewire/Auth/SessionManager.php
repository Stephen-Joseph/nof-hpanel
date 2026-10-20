<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

class SessionManager extends Component
{
    public string $password = '';

    public bool $confirmingRevokeAll = false;

    public function revokeSession(string $sessionId, AuditService $audit): void
    {
        if ($sessionId === Session::getId()) {
            return;
        }

        DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->delete();

        /** @var User $user */
        $user = Auth::user();
        $audit->log('auth.session.revoked', $user, null, ['session_id' => $sessionId]);
    }

    public function revokeOtherSessions(AuditService $audit): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password does not match your current password.',
            ]);
        }

        $currentSessionId = Session::getId();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $this->password = '';
        $this->confirmingRevokeAll = false;

        $audit->log('auth.session.revoked_others', $user);
    }

    public function render(): View
    {
        $sessions = collect(
            DB::table('sessions')
                ->where('user_id', Auth::id())
                ->orderBy('last_activity', 'desc')
                ->get()
        )->map(function (object $session): array {
            return [
                'id' => (string) $session->id,
                'ip_address' => (string) $session->ip_address,
                'user_agent' => (string) $session->user_agent,
                'is_current' => (string) $session->id === Session::getId(),
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            ];
        });

        return view('livewire.auth.session-manager', [
            'sessions' => $sessions,
        ]);
    }
}
