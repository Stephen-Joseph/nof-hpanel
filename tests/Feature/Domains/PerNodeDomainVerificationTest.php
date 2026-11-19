<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\IssueSslCertificateJob;
use App\Livewire\Servers\ServerForm;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\Dns\DnsResolverInterface;
use App\Services\DomainVerificationService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->fakeResolver = new FakeDnsResolver;
    $this->app->instance(DnsResolverInterface::class, $this->fakeResolver);

    $this->admin = User::factory()->admin()->create();
    $tokenData = ApiToken::createToken($this->admin, 'Admin Token');
    $this->token = $tokenData['plainTextToken'];

    $this->group = ServerGroup::create([
        'name' => 'Primary Cluster',
        'slug' => 'primary-cluster',
        'location' => 'US-East',
    ]);

    // Node 1
    $this->node1 = Server::create([
        'name' => 'Node 1 (US-East)',
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
        'public_ip' => '198.51.100.10',
        'public_ipv6' => '2001:db8:1::1',
        'edge_host' => 'edge-node-1.nexorafox.in',
        'agent_url' => 'https://10.0.0.10:8443',
        'status' => ServerStatus::ACTIVE,
        'server_group_id' => $this->group->id,
        'max_accounts' => 50,
    ]);

    // Node 2
    $this->node2 = Server::create([
        'name' => 'Node 2 (EU-West)',
        'hostname' => 'node2.example.com',
        'private_ip' => '10.0.0.20',
        'public_ip' => '198.51.100.20',
        'public_ipv6' => '2001:db8:2::1',
        'edge_host' => 'edge-node-2.nexorafox.in',
        'agent_url' => 'https://10.0.0.20:8443',
        'status' => ServerStatus::ACTIVE,
        'server_group_id' => $this->group->id,
        'max_accounts' => 50,
    ]);

    $this->package = Package::factory()->create();
    $this->customer = User::factory()->customer()->create();

    // Account hosted on Node 2
    $this->accountOnNode2 = Account::factory()->create([
        'user_id' => $this->customer->id,
        'server_id' => $this->node2->id,
        'package_id' => $this->package->id,
        'linux_username' => 'node2user',
        'primary_domain' => 'node2domain.com',
    ]);

    $this->domainOnNode2 = Domain::factory()->create([
        'account_id' => $this->accountOnNode2->id,
        'domain' => 'node2domain.com',
        'type' => DomainType::PRIMARY,
        'is_apex' => true,
        'verification_status' => 'pending_dns',
    ]);
});

test('domain on node 2 verifies with CNAME to node 2 edge_host', function () {
    $service = app(DomainVerificationService::class);

    // TXT ownership record
    $this->fakeResolver->setTxt(
        '_hpanel-verify.node2domain.com',
        'hpanel-verify='.$this->domainOnNode2->verification_token
    );

    // CNAME points to Node 2's specific edge host
    $this->fakeResolver->setCname('node2domain.com', $this->node2->edge_host);

    $result = $service->verify($this->domainOnNode2);

    expect($result->ownershipOk)->toBeTrue()
        ->and($result->routingOk)->toBeTrue()
        ->and($result->verified)->toBeTrue()
        ->and($this->domainOnNode2->fresh()->verification_status)->toBe('verified');
});

test('domain on node 2 verifies with A record = node 2 public_ip', function () {
    $service = app(DomainVerificationService::class);

    // TXT ownership record
    $this->fakeResolver->setTxt(
        '_hpanel-verify.node2domain.com',
        'hpanel-verify='.$this->domainOnNode2->verification_token
    );

    // A record matches Node 2's public IP
    $this->fakeResolver->setA('node2domain.com', $this->node2->public_ip);

    $result = $service->verify($this->domainOnNode2);

    expect($result->ownershipOk)->toBeTrue()
        ->and($result->routingOk)->toBeTrue()
        ->and($result->verified)->toBeTrue()
        ->and($this->domainOnNode2->fresh()->verification_status)->toBe('verified');
});

test('domain fails with CNAME to node 1 edge_host with cross-node pointing error message', function () {
    $service = app(DomainVerificationService::class);

    // TXT ownership record matches
    $this->fakeResolver->setTxt(
        '_hpanel-verify.node2domain.com',
        'hpanel-verify='.$this->domainOnNode2->verification_token
    );

    // BUT CNAME points to Node 1 instead of Node 2!
    $this->fakeResolver->setCname('node2domain.com', $this->node1->edge_host);

    $result = $service->verify($this->domainOnNode2);

    expect($result->ownershipOk)->toBeTrue()
        ->and($result->routingOk)->toBeFalse()
        ->and($result->verified)->toBeFalse();

    $error = $this->domainOnNode2->fresh()->dns_last_error;
    expect($error)->toContain('points to another node')
        ->and($error)->toContain('Node 1')
        ->and($error)->toContain('Node 2');
});

test('domain fails with A record pointing to node 1 public_ip with cross-node message', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setTxt(
        '_hpanel-verify.node2domain.com',
        'hpanel-verify='.$this->domainOnNode2->verification_token
    );

    // A record points to Node 1's public IP instead of Node 2
    $this->fakeResolver->setA('node2domain.com', $this->node1->public_ip);

    $result = $service->verify($this->domainOnNode2);

    expect($result->routingOk)->toBeFalse()
        ->and($result->verified)->toBeFalse();

    $error = $this->domainOnNode2->fresh()->dns_last_error;
    expect($error)->toContain('points to another node')
        ->and($error)->toContain($this->node1->public_ip);
});

test('changing account server_id resets domain verification_status to pending_dns', function () {
    $this->domainOnNode2->update([
        'verification_status' => 'verified',
        'verified_at' => now(),
    ]);

    expect($this->domainOnNode2->fresh()->verification_status)->toBe('verified');

    // Change account server to Node 1
    $this->accountOnNode2->update([
        'server_id' => $this->node1->id,
    ]);

    expect($this->domainOnNode2->fresh()->verification_status)->toBe('pending_dns')
        ->and($this->domainOnNode2->fresh()->dns_last_error)->toContain('Server node changed');
});

test('billing API returns dns object corresponding to assigned node', function () {
    $response = $this->withToken($this->token)->getJson(route('api.v1.accounts.show', $this->accountOnNode2->id));

    $response->assertOk()
        ->assertJson([
            'id' => $this->accountOnNode2->id,
            'server' => [
                'id' => $this->node2->id,
                'name' => $this->node2->name,
            ],
            'dns' => [
                'node_name' => $this->node2->name,
                'edge_host' => $this->node2->edge_host,
                'a_records' => [$this->node2->public_ip, $this->node2->public_ipv6],
                'txt_name' => '_hpanel-verify.node2domain.com',
                'txt_value' => 'hpanel-verify='.$this->domainOnNode2->verification_token,
                'verification_status' => 'pending_dns',
            ],
        ]);
});

test('billing API store selects server by capacity and returns node dns details', function () {
    Queue::fake();

    // Node 1 has 1 account, Node 2 has 2 accounts -> Scheduler should pick Node 1
    Account::factory()->create(['server_id' => $this->node2->id]);

    $payload = [
        'customer_name' => 'Auto Node Customer',
        'customer_email' => 'autonode@example.com',
        'username' => 'autonode1',
        'domain' => 'autonode.com',
        'package_id' => $this->package->id,
    ];

    $response = $this->withToken($this->token)->postJson(route('api.v1.accounts.store'), $payload);

    $response->assertStatus(202)
        ->assertJsonStructure([
            'account_id',
            'username',
            'domain',
            'primary_domain',
            'dns' => [
                'node_name',
                'edge_host',
                'a_records',
                'txt_name',
                'txt_value',
                'verification_status',
            ],
        ]);

    $data = $response->json();
    expect($data['dns']['node_name'])->toBe($this->node1->name)
        ->and($data['dns']['edge_host'])->toBe($this->node1->edge_host)
        ->and($data['dns']['a_records'])->toContain($this->node1->public_ip);
});

test('SSL preflight checks account node public_ip and rejects mismatch with DNS_NOT_POINTING', function () {
    $this->domainOnNode2->update([
        'verification_status' => 'verified',
    ]);

    // Public DNS resolves to a completely different IP (e.g. Cloudflare / old host)
    $this->fakeResolver->setA('node2domain.com', '93.184.216.34');

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldNotReceive('execute');

    $webhooks = app(WebhookService::class);
    $job = new IssueSslCertificateJob($this->domainOnNode2, 'admin@example.com');
    $job->handle($mockClient, $webhooks, $this->fakeResolver);

    expect($this->domainOnNode2->fresh()->ssl_status)->toBe('failed')
        ->and($this->domainOnNode2->fresh()->ssl_last_error)->toContain('DNS_NOT_POINTING');
});

test('node enrollment rejects private and reserved IPs as public_ip', function (string $invalidIp) {
    $rawToken = Str::random(64);
    ServerEnrollmentToken::create([
        'token_hash' => ServerEnrollmentToken::hashToken($rawToken),
        'server_name' => 'Invalid-Node',
        'server_group_id' => $this->group->id,
        'created_by' => $this->admin->id,
        'expires_at' => now()->addMinutes(60),
    ]);

    $response = $this->postJson(route('api.v1.servers.enroll'), [
        'token' => $rawToken,
        'hostname' => 'invalid-node.example.com',
        'private_ip' => '10.200.0.99',
        'public_ip' => $invalidIp,
        'agent_url' => 'https://10.200.0.99:8443',
    ]);

    $response->assertStatus(422);
})->with([
    '10.0.0.1',
    '172.16.5.1',
    '172.31.255.255',
    '192.168.1.100',
    '127.0.0.1',
    '169.254.1.1',
]);

test('server model checkEdgeDns resolves edge_host and updates edge_dns_ok flag', function () {
    // Unresolved or mismatched
    $this->fakeResolver->setA($this->node1->edge_host, '93.184.216.34');
    $ok = $this->node1->checkEdgeDns($this->fakeResolver);

    expect($ok)->toBeFalse()
        ->and($this->node1->fresh()->edge_dns_ok)->toBeFalse()
        ->and($this->node1->fresh()->edge_dns_checked_at)->not->toBeNull();

    // Resolved matching node1 public_ip
    $this->fakeResolver->setA($this->node1->edge_host, $this->node1->public_ip);
    $ok = $this->node1->checkEdgeDns($this->fakeResolver);

    expect($ok)->toBeTrue()
        ->and($this->node1->fresh()->edge_dns_ok)->toBeTrue();
});

test('artisan hpanel:backfill-nodes command updates missing fields and re-verifies domains', function () {
    $nodeWithoutEdge = Server::create([
        'name' => 'Backfill Node',
        'hostname' => 'backfill.example.com',
        'private_ip' => '10.0.5.1',
        'public_ip' => null,
        'edge_host' => null,
        'agent_url' => 'https://198.51.100.99:8443',
        'status' => ServerStatus::ACTIVE,
    ]);

    $acc = Account::factory()->create([
        'server_id' => $nodeWithoutEdge->id,
        'primary_domain' => 'backfilled.com',
    ]);
    $dom = Domain::factory()->create([
        'account_id' => $acc->id,
        'domain' => 'backfilled.com',
        'verification_status' => 'pending_dns',
    ]);

    $exitCode = Artisan::call('hpanel:backfill-nodes', ['--force' => true]);
    expect($exitCode)->toBe(0);

    $nodeWithoutEdge->refresh();
    expect($nodeWithoutEdge->public_ip)->toBe('198.51.100.99')
        ->and($nodeWithoutEdge->edge_host)->not->toBeNull()
        ->and($nodeWithoutEdge->edge_host)->toContain('edge-backfill-node');
});

test('server form validates public_ip, public_ipv6 and edge_host', function () {
    Livewire::actingAs($this->admin)
        ->test(ServerForm::class)
        ->set('name', 'Frankfurt Node')
        ->set('hostname', 'fra.example.com')
        ->set('private_ip', '10.0.9.1')
        ->set('public_ip', '192.168.1.1') // Private IP should fail
        ->set('agent_url', 'https://10.0.9.1:8443')
        ->call('save')
        ->assertHasErrors(['public_ip']);

    Livewire::actingAs($this->admin)
        ->test(ServerForm::class)
        ->set('name', 'Frankfurt Node')
        ->set('hostname', 'fra.example.com')
        ->set('private_ip', '10.0.9.1')
        ->set('public_ip', '198.51.100.55') // Public IP should pass
        ->set('public_ipv6', '2001:db8::1')
        ->set('agent_url', 'https://10.0.9.1:8443')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('servers.index'));

    $created = Server::where('hostname', 'fra.example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->public_ip)->toBe('198.51.100.55')
        ->and($created->public_ipv6)->toBe('2001:db8::1')
        ->and($created->edge_host)->toBe('edge-frankfurt-node.'.config('hpanel.edge_base', 'nexorafox.in'));
});
