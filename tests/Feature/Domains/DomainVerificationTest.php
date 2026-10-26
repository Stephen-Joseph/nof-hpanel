<?php

use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\IssueSslCertificateJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\Domains\DnsHelper;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\Dns\DnsResolverInterface;
use App\Services\DomainVerificationService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->fakeResolver = new FakeDnsResolver;
    $this->app->instance(DnsResolverInterface::class, $this->fakeResolver);

    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.15']);
    $this->package = Package::factory()->create();

    $this->customer1 = User::factory()->customer()->create(['email' => 'alice@corp.com']);
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);
    $this->apexDomain = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'alicecorp.com',
        'type' => DomainType::PRIMARY,
        'is_apex' => true,
        'verification_status' => 'pending_dns',
    ]);
    $this->subDomain = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'app.alicecorp.com',
        'type' => DomainType::SUBDOMAIN,
        'is_apex' => false,
        'verification_status' => 'pending_dns',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
    ]);
    $this->bobDomain = Domain::factory()->create([
        'account_id' => $this->account2->id,
        'domain' => 'bobinc.com',
        'type' => DomainType::PRIMARY,
        'is_apex' => true,
        'verification_status' => 'pending_dns',
    ]);
});

test('ownership passes when TXT record matches expected token', function () {
    $service = app(DomainVerificationService::class);

    // TXT record present with exact token
    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify='.$this->subDomain->verification_token);
    $this->fakeResolver->setCname('app.alicecorp.com', 'edge.nexorafox.in');

    $result = $service->verify($this->subDomain);

    expect($result->ownershipOk)->toBeTrue()
        ->and($result->routingOk)->toBeTrue()
        ->and($result->verified)->toBeTrue();

    expect($this->subDomain->fresh()->verification_status)->toBe('verified')
        ->and($this->subDomain->fresh()->verified_at)->not->toBeNull();
});

test('ownership fails when TXT record is missing', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setCname('app.alicecorp.com', 'edge.nexorafox.in');

    $result = $service->verify($this->subDomain);

    expect($result->ownershipOk)->toBeFalse()
        ->and($result->verified)->toBeFalse();

    expect($this->subDomain->fresh()->verification_status)->toBe('pending_dns')
        ->and($this->subDomain->fresh()->dns_last_error)->toContain('TXT record');
});

test('ownership fails when TXT record contains wrong token', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify=wrong-token-abc');
    $this->fakeResolver->setCname('app.alicecorp.com', 'edge.nexorafox.in');

    $result = $service->verify($this->subDomain);

    expect($result->ownershipOk)->toBeFalse()
        ->and($result->verified)->toBeFalse();

    expect($this->subDomain->fresh()->verification_status)->toBe('pending_dns')
        ->and($this->subDomain->fresh()->dns_last_error)->toContain('TXT record');
});

test('routing passes for non-apex domain via CNAME with normalized lowercase and trailing dot', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify='.$this->subDomain->verification_token);
    // DNS returns uppercase with trailing dot
    $this->fakeResolver->setCname('app.alicecorp.com', 'EDGE.NEXORAFOX.IN.');

    $result = $service->verify($this->subDomain);

    expect($result->routingOk)->toBeTrue()
        ->and($result->verified)->toBeTrue();
});

test('routing passes for non-apex domain via www subdomain CNAME', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify='.$this->subDomain->verification_token);
    $this->fakeResolver->setCname('www.app.alicecorp.com', 'edge.nexorafox.in');

    $result = $service->verify($this->subDomain);

    expect($result->routingOk)->toBeTrue()
        ->and($result->verified)->toBeTrue();
});

test('routing passes for apex domain via A record matching resolved edge_host IP at check time', function () {
    $service = app(DomainVerificationService::class);

    // edge_host resolves dynamically at check time to 203.0.113.10
    $this->fakeResolver->setA('edge.nexorafox.in', '203.0.113.10');
    // Apex domain matches that IP
    $this->fakeResolver->setA('alicecorp.com', '203.0.113.10');
    $this->fakeResolver->setTxt('_hpanel-verify.alicecorp.com', 'hpanel-verify='.$this->apexDomain->verification_token);

    $result = $service->verify($this->apexDomain);

    expect($result->routingOk)->toBeTrue()
        ->and($result->ownershipOk)->toBeTrue()
        ->and($result->verified)->toBeTrue();

    expect($this->apexDomain->fresh()->verification_status)->toBe('verified');
});

test('routing fails on wrong target', function () {
    $service = app(DomainVerificationService::class);

    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify='.$this->subDomain->verification_token);
    $this->fakeResolver->setCname('app.alicecorp.com', 'other.wronghost.com');

    $result = $service->verify($this->subDomain);

    expect($result->routingOk)->toBeFalse()
        ->and($result->verified)->toBeFalse();

    expect($this->subDomain->fresh()->dns_last_error)->toContain('CNAME record does not resolve to edge.nexorafox.in');
});

test('verified domain stays verified after one transient DNS error, downgrading only after 3 consecutive failures', function () {
    $service = app(DomainVerificationService::class);

    // Initial state: Domain is already verified
    $this->subDomain->update([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'dns_consecutive_failures' => 0,
    ]);

    // Transient failure: DNS returns empty (lookup timeout or network glitch)
    $this->fakeResolver->clear();

    // 1st transient failure
    $result1 = $service->verify($this->subDomain);
    expect($result1->verified)->toBeFalse();
    expect($this->subDomain->fresh()->verification_status)->toBe('verified')
        ->and($this->subDomain->fresh()->dns_consecutive_failures)->toBe(1);

    // 2nd transient failure
    $result2 = $service->verify($this->subDomain);
    expect($result2->verified)->toBeFalse();
    expect($this->subDomain->fresh()->verification_status)->toBe('verified')
        ->and($this->subDomain->fresh()->dns_consecutive_failures)->toBe(2);

    // 3rd consecutive failure -> downgrades to failed
    $result3 = $service->verify($this->subDomain);
    expect($result3->verified)->toBeFalse();
    expect($this->subDomain->fresh()->verification_status)->toBe('failed')
        ->and($this->subDomain->fresh()->dns_consecutive_failures)->toBe(3);
});

test('SSL is refused when domain is not verified', function () {
    Queue::fake();

    $this->subDomain->update(['verification_status' => 'pending_dns']);

    // Attempting to enable SSL via Livewire DnsHelper
    Livewire::actingAs($this->customer1)
        ->test(DnsHelper::class, ['domain' => $this->subDomain])
        ->call('enableSsl')
        ->assertSee('Domain must be verified before requesting an SSL certificate');

    Queue::assertNothingPushed();

    // Directly invoking IssueSslCertificateJob on unverified domain marks failed without throwing
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldNotReceive('execute');

    $webhooks = app(WebhookService::class);
    $job = new IssueSslCertificateJob($this->subDomain, 'admin@alicecorp.com');
    $job->handle($mockClient, $webhooks);

    expect($this->subDomain->fresh()->ssl_status)->toBe('failed')
        ->and($this->subDomain->fresh()->ssl_last_error)->toContain('Domain must be verified before requesting SSL');
});

test('SSL job sets active on agent success and failed on agent error without throwing', function () {
    Queue::fake([WriteVhostJob::class]);
    $this->apexDomain->update(['verification_status' => 'verified', 'verified_at' => now()]);

    $webhooks = app(WebhookService::class);

    // 1. Success path
    $mockSuccessClient = Mockery::mock(AgentClient::class);
    $mockSuccessClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action) => $action === 'ssl.issue')
        ->andReturn([
            'success' => true,
            'data' => [
                'cert_path' => '/etc/ssl/live/alicecorp.com/cert.pem',
                'valid_from' => now()->toIso8601String(),
                'valid_to' => now()->addDays(90)->toIso8601String(),
            ],
            'logs' => ['Issued cert successfully'],
        ]);

    $jobSuccess = new IssueSslCertificateJob($this->apexDomain, 'admin@alicecorp.com');
    $jobSuccess->handle($mockSuccessClient, $webhooks);

    expect($this->apexDomain->fresh()->ssl_status)->toBe('active')
        ->and($this->apexDomain->fresh()->ssl_expires_at)->not->toBeNull()
        ->and($this->apexDomain->fresh()->ssl_last_error)->toBeNull();

    // 2. Agent error path without throwing
    $mockErrorClient = Mockery::mock(AgentClient::class);
    $mockErrorClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action) => $action === 'ssl.issue')
        ->andReturn([
            'success' => false,
            'error' => 'Rate limit exceeded: 5 certificates per week',
            'logs' => ['Rate limited by ACME provider'],
        ]);

    $jobError = new IssueSslCertificateJob($this->apexDomain, 'admin@alicecorp.com');
    $jobError->handle($mockErrorClient, $webhooks); // Must not throw

    expect($this->apexDomain->fresh()->ssl_status)->toBe('failed')
        ->and($this->apexDomain->fresh()->ssl_last_error)->toContain('Rate limit exceeded');

    // 3. Exception path without throwing
    $mockExceptionClient = Mockery::mock(AgentClient::class);
    $mockExceptionClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action) => $action === 'ssl.issue')
        ->andThrow(new \RuntimeException('Connection timed out to node agent'));

    $jobException = new IssueSslCertificateJob($this->apexDomain, 'admin@alicecorp.com');
    $jobException->handle($mockExceptionClient, $webhooks); // Must not throw

    expect($this->apexDomain->fresh()->ssl_status)->toBe('failed')
        ->and($this->apexDomain->fresh()->ssl_last_error)->toContain('Connection timed out');
});

test('policy: customer cannot verify or request SSL on another customer domain', function () {
    // Customer 1 cannot access Customer 2's DNS verification page
    $this->actingAs($this->customer1)
        ->get(route('domains.dns', $this->bobDomain))
        ->assertForbidden();

    // Customer 1 cannot call verifyDns or enableSsl on Customer 2's domain
    Livewire::actingAs($this->customer1)
        ->test(DnsHelper::class, ['domain' => $this->bobDomain])
        ->assertForbidden();

    // Policy direct check
    expect($this->customer1->can('verify', $this->bobDomain))->toBeFalse()
        ->and($this->customer1->can('requestSsl', $this->bobDomain))->toBeFalse()
        ->and($this->customer1->can('verify', $this->apexDomain))->toBeTrue()
        ->and($this->customer1->can('requestSsl', $this->apexDomain))->toBeTrue();

    // Admin can verify any domain
    $admin = User::factory()->admin()->create();
    expect($admin->can('verify', $this->bobDomain))->toBeTrue()
        ->and($admin->can('requestSsl', $this->bobDomain))->toBeTrue();

    // Reseller can verify their customer domain
    $reseller = User::factory()->reseller()->create();
    $childCustomer = User::factory()->customer()->create(['parent_id' => $reseller->id]);
    $resellerAccount = Account::factory()->create(['user_id' => $childCustomer->id, 'server_id' => $this->server->id]);
    $childDomain = Domain::factory()->create(['account_id' => $resellerAccount->id, 'domain' => 'child.com']);

    expect($reseller->can('verify', $childDomain))->toBeTrue()
        ->and($reseller->can('requestSsl', $childDomain))->toBeTrue();
});

test('verify pending domains command processes pending domains', function () {
    $this->fakeResolver->setTxt('_hpanel-verify.app.alicecorp.com', 'hpanel-verify='.$this->subDomain->verification_token);
    $this->fakeResolver->setCname('app.alicecorp.com', 'edge.nexorafox.in');

    Artisan::call('domains:verify-pending', ['--limit' => 200]);

    expect($this->subDomain->fresh()->verification_status)->toBe('verified');
});

