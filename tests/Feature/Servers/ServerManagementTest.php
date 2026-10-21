<?php

use App\Enums\ServerStatus;
use App\Livewire\Servers\ServerForm;
use App\Livewire\Servers\ServerList;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reseller = User::factory()->reseller()->create();
    $this->customer = User::factory()->customer()->create();
});

test('non-admins cannot access server management pages', function () {
    $this->actingAs($this->reseller)->get('/servers')->assertStatus(403);
    $this->actingAs($this->reseller)->get('/servers/create')->assertStatus(403);
    $this->actingAs($this->customer)->get('/servers')->assertStatus(403);
});

test('admin can view servers list and register a new server', function () {
    $this->actingAs($this->admin)->get('/servers')->assertStatus(200);

    Livewire::actingAs($this->admin)
        ->test(ServerForm::class)
        ->set('name', 'US East Node 2')
        ->set('hostname', 'node2.us-east.hpanel.internal')
        ->set('private_ip', '10.200.1.20')
        ->set('agent_url', 'https://10.200.1.20:8443')
        ->set('status', ServerStatus::ACTIVE->value)
        ->set('max_accounts', 200)
        ->set('cpu_cores', 16)
        ->set('memory_mb', 32768)
        ->set('storage_mb', 1000000)
        ->call('save')
        ->assertRedirect(route('servers.index'));

    $this->assertDatabaseHas('servers', [
        'hostname' => 'node2.us-east.hpanel.internal',
        'private_ip' => '10.200.1.20',
        'max_accounts' => 200,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'server.created',
        'user_id' => $this->admin->id,
    ]);
});

test('admin cannot delete a server node with hosted accounts', function () {
    $server = Server::factory()->create();
    Account::factory()->create(['server_id' => $server->id]);

    Livewire::actingAs($this->admin)
        ->test(ServerList::class)
        ->call('deleteServer', $server->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('servers', ['id' => $server->id]);
});

test('admin can delete an empty server node', function () {
    $server = Server::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ServerList::class)
        ->call('deleteServer', $server->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'server.deleted',
        'user_id' => $this->admin->id,
    ]);
});
