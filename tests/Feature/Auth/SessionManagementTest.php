<?php

use App\Livewire\Auth\SessionManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('user can list and revoke other sessions via livewire', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    DB::table('sessions')->insert([
        [
            'id' => 'current-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Test Chrome',
            'payload' => serialize([]),
            'last_activity' => time(),
        ],
        [
            'id' => 'other-session-id',
            'user_id' => $user->id,
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test Safari',
            'payload' => serialize([]),
            'last_activity' => time() - 3600,
        ],
    ]);

    Livewire::test(SessionManager::class)
        ->call('revokeSession', 'other-session-id');

    $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
    $this->assertDatabaseHas('sessions', ['id' => 'current-session-id']);
});
