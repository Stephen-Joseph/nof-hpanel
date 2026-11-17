<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Livewire\Domains\DnsHelper;
use App\Livewire\Domains\DomainList;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\IdempotencyKey;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\DomainZoneResolver;
use App\Services\DomainVerificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->fakeResolver = new FakeDnsResolver;
    $this->app->instance(DnsResolverInterface::class, $this->fakeResolver);

    config(['hpanel.edge_host' => 'edge.example.com']);

    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'public_ip' => '198.51.100.5',
        'private_ip' => '10.0.0.15',
    ]);
    $this->package = Package::factory()->create(['name' => 'Starter']);

    $this->client = User::factory()->customer()->create(['email' => 'client@corp.test']);

    // Two accounts for one client
    $this->accountA = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'clienta',
        'primary_domain' => 'client-a.test',
    ]);

    $this->accountB = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'clientb',
        'primary_domain' => 'client-b.test',
    ]);

    $this->domainA = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'client-a.test',
        'type' => DomainType::PRIMARY,
        'verification_status' => 'pending_dns',
        'verification_token' => 'token_aaa_111122223333444455556666',
    ]);

    $this->domainB = Domain::factory()->create([
        'account_id' => $this->accountB->id,
        'domain' => 'client-b.test',
        'type' => DomainType::PRIMARY,
        'verification_status' => 'pending_dns',
        'verification_token' => 'token_bbb_999988887777666655554444',
    ]);
});

test('1. domain cards must not share state in Livewire, using wire:key and id-based actions', function () {
    $this->actingAs($this->client);

    // Setup DNS (ownership TXT + routing A) for domainA only
    $this->fakeResolver->setTxt(
        '_hpanel-verify.client-a.test',
        "hpanel-verify={$this->domainA->verification_token}"
    );
    $this->fakeResolver->setA('client-a.test', $this->server->public_ip);

    // Test Livewire component rendering has wire:key and wire:click with explicit ID
    $component = Livewire::test(DomainList::class)
        ->assertSeeHtml('wire:key="domain-'.$this->domainA->id.'"')
        ->assertSeeHtml('wire:key="domain-'.$this->domainB->id.'"')
        ->assertSeeHtml('wire:click="verify('.$this->domainA->id.')"')
        ->assertSeeHtml('wire:click="enableSsl('.$this->domainA->id.')"');

    // Trigger verify on Domain A only
    $component->call('verify', $this->domainA->id);

    $this->domainA->refresh();
    $this->domainB->refresh();

    expect($this->domainA->verification_status)->toBe('verified')
        ->and($this->domainB->verification_status)->toBe('pending_dns');

    // Ensure another user cannot verify Domain A (policy protection)
    $otherUser = User::factory()->customer()->create();
    $this->actingAs($otherUser);

    Livewire::test(DomainList::class)
        ->call('verify', $this->domainA->id)
        ->assertForbidden();
});

test('2. records show the right relative host for a subdomain and an apex', function () {
    $zoneResolver = app(DomainZoneResolver::class);

    // Subdomain: steram.meno.fun in zone meno.fun
    $subInfo = $zoneResolver->resolveZoneAndRelativeHost('steram.meno.fun');
    expect($subInfo['zone'])->toBe('meno.fun')
        ->and($subInfo['relative_host'])->toBe('steram')
        ->and($subInfo['txt_host'])->toBe('_hpanel-verify.steram')
        ->and($subInfo['txt_full'])->toBe('_hpanel-verify.steram.meno.fun')
        ->and($subInfo['cname_host'])->toBe('steram');

    // Apex: meno.fun in zone meno.fun
    $apexInfo = $zoneResolver->resolveZoneAndRelativeHost('meno.fun');
    expect($apexInfo['zone'])->toBe('meno.fun')
        ->and($apexInfo['relative_host'])->toBe('@')
        ->and($apexInfo['txt_host'])->toBe('_hpanel-verify')
        ->and($apexInfo['txt_full'])->toBe('_hpanel-verify.meno.fun')
        ->and($apexInfo['cname_host'])->toBe('@');

    // DnsHelper Livewire component displays zone details and relative host
    $this->actingAs($this->client);
    Livewire::test(DnsHelper::class, ['domain' => $this->domainA])
        ->assertSee($this->domainA->domain)
        ->assertSee('_hpanel-verify')
        ->assertSee('What We Found')
        ->assertSee('Verify Now');
});

test('3. fake resolver covers TXT ok, wrong token, CNAME ok, apex A ok, HTTP proof ok, and 3-failure downgrade', function () {
    $service = app(DomainVerificationService::class);

    // Case A: TXT ok (including whitespace and quotes) + route ok
    $this->fakeResolver->clear();
    $this->fakeResolver->setTxt(
        '_hpanel-verify.client-a.test',
        '"hpanel-verify='.$this->domainA->verification_token.'"'
    );
    $this->fakeResolver->setA('client-a.test', $this->server->public_ip);
    $resA = $service->verify($this->domainA);
    expect($resA->verified)->toBeTrue()
        ->and($this->domainA->fresh()->verification_status)->toBe('verified');

    // Case B: Wrong token
    $this->fakeResolver->clear();
    $this->fakeResolver->setTxt(
        '_hpanel-verify.client-a.test',
        'hpanel-verify=completely_wrong_token'
    );
    $resWrong = $service->verify($this->domainA);
    expect($resWrong->verified)->toBeFalse();

    // Case C: CNAME ok (points to edge hostname)
    $subDomain = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'sub.client-a.test',
        'type' => DomainType::SUBDOMAIN,
        'verification_status' => 'pending_dns',
        'verification_token' => 'token_sub_123456789012345678901234',
    ]);
    $this->fakeResolver->clear();
    $this->fakeResolver->setTxt('_hpanel-verify.sub.client-a.test', "hpanel-verify={$subDomain->verification_token}");
    $this->fakeResolver->setCname('sub.client-a.test', 'edge.example.com');
    $this->fakeResolver->setA('edge.example.com', '198.51.100.5');

    $resCname = $service->verify($subDomain);
    expect($resCname->verified)->toBeTrue()
        ->and($resCname->routingOk)->toBeTrue();

    // Case D: Apex A ok (matches node public IP)
    $apex = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'apex-test.test',
        'type' => DomainType::PRIMARY,
        'verification_status' => 'pending_dns',
        'verification_token' => 'token_apex_111122223333444455556666',
    ]);
    $this->fakeResolver->clear();
    $this->fakeResolver->setTxt('_hpanel-verify.apex-test.test', "hpanel-verify={$apex->verification_token}");
    $this->fakeResolver->setA('apex-test.test', '198.51.100.5');

    $resApex = $service->verify($apex);
    expect($resApex->verified)->toBeTrue()
        ->and($resApex->routingOk)->toBeTrue();

    // Case E: HTTP proof ok
    $httpProofDomain = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'http-proof.test',
        'type' => DomainType::PRIMARY,
        'verification_status' => 'pending_dns',
        'verification_token' => 'token_http_111122223333444455556666',
    ]);
    $this->fakeResolver->clear();
    // Route matches A record
    $this->fakeResolver->setA('http-proof.test', '198.51.100.5');

    // Fake agent client for domain.http_probe action
    $mockAgent = Mockery::mock(AgentClient::class);
    $mockAgent->shouldReceive('execute')
        ->andReturn(['success' => true]);

    // Fake HTTP probe URL fetch
    Http::fake([
        "http://http-proof.test/.well-known/hpanel-verify/{$httpProofDomain->verification_token}" => Http::response(
            $httpProofDomain->verification_token,
            200
        ),
    ]);

    $httpService = new DomainVerificationService($this->fakeResolver, $mockAgent);
    $resHttp = $httpService->verify($httpProofDomain);
    expect($resHttp->verified)->toBeTrue()
        ->and($resHttp->ownershipOk)->toBeTrue();

    // Case F: 3-consecutive-failure downgrade
    $verifiedDomain = Domain::factory()->create([
        'account_id' => $this->accountA->id,
        'domain' => 'downgrade-test.test',
        'type' => DomainType::PRIMARY,
        'verification_status' => 'verified',
        'dns_consecutive_failures' => 0,
        'verification_token' => 'token_down_111122223333444455556666',
    ]);

    $this->fakeResolver->clear(); // No DNS records -> check fails

    // Failure 1: transient DNS failure does NOT downgrade
    $service->verify($verifiedDomain);
    $verifiedDomain->refresh();
    expect($verifiedDomain->verification_status)->toBe('verified')
        ->and($verifiedDomain->dns_consecutive_failures)->toBe(1);

    // Failure 2: still verified
    $service->verify($verifiedDomain);
    $verifiedDomain->refresh();
    expect($verifiedDomain->verification_status)->toBe('verified')
        ->and($verifiedDomain->dns_consecutive_failures)->toBe(2);

    // Failure 3: now downgraded after 3 consecutive failures
    $service->verify($verifiedDomain);
    $verifiedDomain->refresh();
    expect($verifiedDomain->verification_status)->toBe('failed')
        ->and($verifiedDomain->dns_consecutive_failures)->toBe(3);
});

test('4. idempotency key reuse with a different body returns 409', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $tokenData = ApiToken::createToken($admin, 'Admin API Token');
    $token = $tokenData['plainTextToken'];
    $idempotencyKey = 'key-test-uuid-'.uniqid();

    // First request
    $response1 = $this->withHeader('Authorization', 'Bearer '.$token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', [
            'username' => 'accunique1',
            'domain' => 'acc-unique-1.test',
            'package_id' => $this->package->id,
            'customer_email' => 'unique1@example.test',
        ]);

    $response1->assertStatus(202);

    // Second request with SAME key but DIFFERENT body -> 409 Conflict
    $responseConflict = $this->withHeader('Authorization', 'Bearer '.$token)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/accounts', [
            'username' => 'accunique2',
            'domain' => 'acc-unique-2.test',
            'package_id' => $this->package->id,
            'customer_email' => 'unique2@example.test',
        ]);

    $responseConflict->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
});

test('5. backfill --repair creates tokens without regenerating existing ones', function () {
    $acc1 = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'userone',
        'primary_domain' => 'userone.test',
    ]);
    $domain1 = Domain::factory()->create([
        'account_id' => $acc1->id,
        'domain' => 'userone.test',
        'type' => DomainType::PRIMARY,
        'verification_token' => 'fixed_token_111122223333444455556666',
    ]);

    $acc2 = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'usertwo',
        'primary_domain' => 'usertwo.test',
    ]);
    $domain2 = Domain::factory()->create([
        'account_id' => $acc2->id,
        'domain' => 'usertwo.test',
        'type' => DomainType::PRIMARY,
        'verification_token' => null, // Missing token
    ]);

    $acc3 = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'userthree',
        'primary_domain' => 'userthree.test',
    ]);
    // acc3 has no Domain model row

    // Run backfill with --repair
    Artisan::call('hpanel:backfill-domains', ['--repair' => true]);

    $domain1->refresh();
    $domain2->refresh();
    $domain3 = Domain::where('account_id', $acc3->id)->first();

    // Existing token must NOT be regenerated
    expect($domain1->verification_token)->toBe('fixed_token_111122223333444455556666')
        // Missing token was generated
        ->and($domain2->verification_token)->not->toBeNull()
        ->and(strlen($domain2->verification_token))->toBeGreaterThanOrEqual(16)
        // Missing domain was created with token
        ->and($domain3)->not->toBeNull()
        ->and($domain3->type->value)->toBe('primary')
        ->and($domain3->verification_token)->not->toBeNull();

    // Run a second time: idempotent, tokens do not change
    $token2Before = $domain2->verification_token;
    $token3Before = $domain3->verification_token;

    Artisan::call('hpanel:backfill-domains', ['--repair' => true]);

    expect($domain2->fresh()->verification_token)->toBe($token2Before)
        ->and($domain3->fresh()->verification_token)->toBe($token3Before);
});

test('6. domain type stays primary for hostnames with several dots', function () {
    $multiDotAccount = Account::factory()->create([
        'user_id' => $this->client->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'steramuser',
        'primary_domain' => 'steram.meno.fun',
    ]);

    // Backfill --repair creates it as primary
    Artisan::call('hpanel:backfill-domains', ['--repair' => true]);

    $domain = Domain::where('domain', 'steram.meno.fun')->first();

    expect($domain)->not->toBeNull()
        ->and($domain->type->value)->toBe('primary');
});
