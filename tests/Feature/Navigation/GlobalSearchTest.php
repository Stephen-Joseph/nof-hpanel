<?php

use App\Livewire\Navigation\GlobalSearch;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['name' => 'Edge-LON-01', 'private_ip' => '192.168.10.50']);
    $this->package = Package::factory()->create();

    $this->userA = User::factory()->customer()->create(['name' => 'Alice Customer']);
    $this->accountA = Account::factory()->create([
        'user_id' => $this->userA->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'aliceusr',
        'primary_domain' => 'alicedomain.com',
    ]);
    $this->domainA = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'alicedomain.com',
    ]);

    $this->userB = User::factory()->customer()->create(['name' => 'Bob Customer']);
    $this->accountB = Account::factory()->create([
        'user_id' => $this->userB->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobusr',
        'primary_domain' => 'bobdomain.com',
    ]);
    $this->domainB = Domain::factory()->create([
        'account_id' => $this->accountB->id,
        'domain' => 'bobdomain.com',
    ]);
});

test('global search can find panel navigation targets', function () {
    Livewire::actingAs($this->userA)
        ->test(GlobalSearch::class)
        ->set('query', 'Websites')
        ->assertSee('Websites & Domains');
});

test('customer can search and find their own domain and account', function () {
    Livewire::actingAs($this->userA)
        ->test(GlobalSearch::class)
        ->set('query', 'alicedomain')
        ->assertSee('alicedomain.com')
        ->assertSee('aliceusr');
});

test('tenant isolation: customer cannot find other users domains or accounts', function () {
    Livewire::actingAs($this->userA)
        ->test(GlobalSearch::class)
        ->set('query', 'bobdomain')
        ->assertDontSee('bobdomain.com');
});

test('admin can search across all servers and accounts', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(GlobalSearch::class)
        ->set('query', 'Edge-LON')
        ->assertSee('Edge-LON-01')
        ->assertSee('192.168.10.50');
});
