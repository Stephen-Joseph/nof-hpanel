<?php

use App\Enums\AccountStatus;
use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\IssueSslCertificateJob;
use App\Jobs\TerminateAccount;
use App\Livewire\Accounts\AccountCreate;
use App\Livewire\Domains\DomainNginxConfig;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AuditService;
use App\Services\Dns\DnsResolverInterface;
use App\Services\DomainVerificationService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'private_ip' => '10.0.0.15',
        'public_ip' => '203.0.113.10',
    ]);
    $this->package = Package::factory()->create([
        'allowed_php_versions' => ['8.3', '8.2'],
        'max_websites' => 5,
    ]);
    $this->admin = User::factory()->admin()->create();
    $this->customer1 = User::factory()->customer()->create(['email' => 'owner1@example.test']);
    $this->customer2 = User::factory()->customer()->create(['email' => 'owner2@example.test']);
});

it('creates exactly one primary domain row when creating an account via admin UI', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(AccountCreate::class)
        ->set('user_id', $this->customer1->id)
        ->set('package_id', $this->package->id)
        ->set('server_id', $this->server->id)
        ->set('linux_username', 'uiuser1')
        ->set('primary_domain', 'uiuser1.example.com')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::where('linux_username', 'uiuser1')->firstOrFail();
    $domains = Domain::where('account_id', $account->id)->get();

    expect($domains)->toHaveCount(1);
    $domain = $domains->first();
    expect($domain->domain)->toBe('uiuser1.example.com')
        ->and($domain->type)->toBe(DomainType::PRIMARY)
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->document_root)->toBe('/home/uiuser1/public_html')
        ->and($domain->app_profile)->toBe(AppProfile::PHP_GENERIC)
        ->and($domain->php_version)->toBe('8.3')
        ->and($domain->verification_status)->toBe('pending_dns')
        ->and($domain->ssl_status)->toBe('none')
        ->and(strlen((string) $domain->verification_token))->toBe(32);
});

it('creates primary domain on billing API store, includes it in show, and respects Idempotency-Key', function () {
    Queue::fake();

    $plainToken = 'test_api_token_'.bin2hex(random_bytes(8));
    ApiToken::create([
        'user_id' => $this->admin->id,
        'name' => 'Billing Test',
        'prefix' => substr($plainToken, 0, 12),
        'token_hash' => hash('sha256', $plainToken),
        'abilities' => ['accounts:write', 'accounts:read'],
    ]);

    $payload = [
        'username' => 'apiuser1',
        'domain' => 'apiuser1.example.com',
        'package_id' => $this->package->id,
        'customer_email' => 'apiowner@example.test',
        'server_id' => $this->server->id,
    ];

    $headers = [
        'Authorization' => 'Bearer '.$plainToken,
        'Idempotency-Key' => 'idem-key-account-1',
    ];

    $res1 = $this->postJson('/api/v1/accounts', $payload, $headers);
    $res1->assertStatus(202)
        ->assertJsonStructure([
            'account_id',
            'primary_domain' => ['id', 'name', 'verification_status'],
        ]);

    $accountId = $res1->json('account_id');
    expect(Domain::where('domain', 'apiuser1.example.com')->count())->toBe(1);

    // Repeat with same Idempotency-Key
    $res2 = $this->postJson('/api/v1/accounts', $payload, $headers);
    $res2->assertStatus(202);
    expect(Account::where('linux_username', 'apiuser1')->count())->toBe(1);
    expect(Domain::where('domain', 'apiuser1.example.com')->count())->toBe(1);

    // GET /api/v1/accounts/{id} includes domain id, name, verification_status
    $show = $this->getJson("/api/v1/accounts/{$accountId}", [
        'Authorization' => 'Bearer '.$plainToken,
    ]);
    $show->assertOk()
        ->assertJsonPath('domain.name', 'apiuser1.example.com')
        ->assertJsonPath('domain.verification_status', 'pending_dns');
});

it('shows primary domain on Domains page for owner and hides from other customer', function () {
    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'owneracct',
        'primary_domain' => 'owneracct.example.com',
    ]);
    Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'owneracct.example.com',
        'type' => DomainType::PRIMARY,
        'is_primary' => true,
    ]);

    $this->actingAs($this->customer1)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertSee('owneracct.example.com');

    $this->actingAs($this->customer2)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertDontSee('owneracct.example.com');
});

it('backfills missing primary domain rows idempotently via hpanel:backfill-domains', function () {
    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'legacyacct',
        'primary_domain' => 'legacyacct.example.com',
    ]);

    expect(Domain::where('account_id', $account->id)->count())->toBe(0);

    Artisan::call('hpanel:backfill-domains');
    expect(Domain::where('account_id', $account->id)->count())->toBe(1);

    // Idempotent second run
    Artisan::call('hpanel:backfill-domains');
    expect(Domain::where('account_id', $account->id)->count())->toBe(1);
});

it('prevents deleting primary domain while account exists and removes domains on account termination', function () {
    Queue::fake();

    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'termacct',
        'primary_domain' => 'termacct.example.com',
        'status' => AccountStatus::ACTIVE,
    ]);
    $domain = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'termacct.example.com',
        'type' => DomainType::PRIMARY,
        'is_primary' => true,
    ]);

    expect($this->admin->can('delete', $domain))->toBeFalse();
    expect($this->customer1->can('delete', $domain))->toBeFalse();

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')->once()->andReturn([
        'success' => true,
        'data' => ['backup_path' => '/var/backups/termacct.tar.gz'],
        'logs' => ['Terminated'],
    ]);

    $job = new TerminateAccount($account, true);
    $job->handle($mockClient, app(AuditService::class));

    expect(Domain::where('id', $domain->id)->exists())->toBeFalse();
});

it('verifies DNS with fake resolver (TXT ok, wrong token, CNAME ok, apex A ok, 3-failure downgrade) and refuses SSL when unverified', function () {
    $fakeResolver = new FakeDnsResolver;
    $this->app->instance(DnsResolverInterface::class, $fakeResolver);

    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'dnsacct',
        'primary_domain' => 'dnsacct.com',
    ]);

    $apex = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'dnsacct.com',
        'type' => DomainType::PRIMARY,
        'is_primary' => true,
        'is_apex' => true,
        'verification_status' => 'pending_dns',
    ]);

    $sub = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'app.dnsacct.com',
        'type' => DomainType::SUBDOMAIN,
        'is_primary' => false,
        'is_apex' => false,
        'verification_status' => 'pending_dns',
    ]);

    $service = app(DomainVerificationService::class);
    $edgeHost = (string) config('hpanel.edge_host', 'edge.nexorafox.in');

    // Wrong TXT token fails
    $fakeResolver->setTxt("_hpanel-verify.{$sub->domain}", 'hpanel-verify=wrong');
    $fakeResolver->setCname($sub->domain, $edgeHost);
    $resWrong = $service->verify($sub);
    expect($resWrong->verified)->toBeFalse();

    // Correct TXT + CNAME passes
    $fakeResolver->clear();
    $fakeResolver->setTxt("_hpanel-verify.{$sub->domain}", "hpanel-verify={$sub->verification_token}");
    $fakeResolver->setCname($sub->domain, $edgeHost);
    $resCname = $service->verify($sub);
    expect($resCname->verified)->toBeTrue();
    expect($sub->fresh()->verification_status)->toBe('verified');

    // SSL refused when apex is unverified
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldNotReceive('execute');
    $sslJob = new IssueSslCertificateJob($apex, 'owner1@example.test');
    $sslJob->handle($mockClient, app(WebhookService::class));
    expect($apex->fresh()->ssl_status)->toBe('failed');

    // Apex TXT + A match passes
    $fakeResolver->clear();
    $fakeResolver->setA($edgeHost, '203.0.113.10');
    $fakeResolver->setA($apex->domain, '203.0.113.10');
    $fakeResolver->setTxt("_hpanel-verify.{$apex->domain}", "hpanel-verify={$apex->verification_token}");
    $resApex = $service->verify($apex);
    expect($resApex->verified)->toBeTrue();
    expect($apex->fresh()->verification_status)->toBe('verified');

    // 3-failure downgrade
    $fakeResolver->clear();
    $fakeResolver->setA($edgeHost, '203.0.113.10');
    $fakeResolver->setA($apex->domain, '203.0.113.10');
    $service->verify($apex);
    expect($apex->fresh()->verification_status)->toBe('verified');
    $service->verify($apex);
    expect($apex->fresh()->verification_status)->toBe('verified');
    $service->verify($apex);
    expect($apex->fresh()->verification_status)->toBe('failed');
});

it('denies cross-tenant nginx config reads and writes and audits saves without storing config content', function () {
    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'nginxacct',
        'primary_domain' => 'nginxacct.example.com',
    ]);
    $domain = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'nginxacct.example.com',
        'type' => DomainType::PRIMARY,
        'is_primary' => true,
    ]);

    // Cross-tenant denied
    $this->actingAs($this->customer2)
        ->get(route('domains.nginx', $domain))
        ->assertForbidden();

    Livewire::actingAs($this->customer2)
        ->test(DomainNginxConfig::class, ['domain' => $domain])
        ->assertForbidden();

    // Owner allowed to read & save
    RateLimiter::clear('nginx-config-save:'.$this->customer1->id);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'nginx.domain.read')
        ->andReturn([
            'success' => true,
            'data' => [
                'exists' => true,
                'content' => "server {\n# === CUSTOM: EDITABLE ===\n# === END CUSTOM ===\n}",
                'custom_block' => '',
                'lines' => [
                    ['line' => 1, 'text' => 'server {', 'locked' => true],
                ],
                'backups' => [],
            ],
            'logs' => [],
        ]);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'nginx.domain.write')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'nginx_test_output' => 'nginx: syntax is ok',
                'backup_id' => 'nginxacct.example.com.20261112T100000Z',
            ],
            'logs' => [],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(DomainNginxConfig::class, ['domain' => $domain])
        ->set('customBlock', 'gzip on;')
        ->call('saveConfig')
        ->assertSet('statusMessage', 'Nginx configuration validated with nginx -t, saved, and reloaded.');

    $audit = AuditLog::where('action', 'domain.nginx.save')->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect(json_encode($audit->new_values))->not->toContain('gzip on;');
});

test('admin can edit full per-domain vhost in advanced mode and non-admin is forbidden', function () {
    $account = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'adminvhost',
        'primary_domain' => 'adminvhost.example.com',
    ]);
    $domain = Domain::factory()->create([
        'account_id' => $account->id,
        'domain' => 'adminvhost.example.com',
    ]);

    $admin = User::factory()->admin()->create();
    RateLimiter::clear('nginx-config-save:'.$admin->id);
    RateLimiter::clear('nginx-config-save:'.$this->customer1->id);

    $fullVhost = "server {\n    listen 80;\n    server_name adminvhost.example.com;\n    root /home/adminvhost/public_html;\n}";

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($srv, $action) => $action === 'nginx.domain.read')
        ->andReturn([
            'success' => true,
            'data' => [
                'exists' => true,
                'content' => $fullVhost,
                'custom_block' => '',
                'lines' => [
                    ['line' => 1, 'text' => 'server {', 'locked' => true],
                ],
                'backups' => [],
            ],
            'logs' => [],
        ]);
    $mockClient->shouldReceive('execute')
        ->withArgs(function ($srv, $action, $payload) {
            return $action === 'nginx.domain.write'
                && ($payload['advanced_mode'] ?? false) === true
                && str_contains($payload['full_content'] ?? '', 'client_max_body_size 512M;');
        })
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'nginx_test_output' => 'nginx: syntax is ok',
                'backup_id' => 'adminvhost.example.com.20261112T100000Z',
            ],
            'logs' => [],
        ]);
    $this->app->instance(AgentClient::class, $mockClient);

    // Non-admin cannot use advancedMode
    Livewire::actingAs($this->customer1)
        ->test(DomainNginxConfig::class, ['domain' => $domain])
        ->set('advancedMode', true)
        ->set('advancedConfirmDomain', 'adminvhost.example.com')
        ->set('fullContent', $fullVhost)
        ->call('saveConfig')
        ->assertForbidden();

    // Admin can edit full vhost in advancedMode after confirming domain name
    Livewire::actingAs($admin)
        ->test(DomainNginxConfig::class, ['domain' => $domain])
        ->set('advancedMode', true)
        ->set('advancedConfirmDomain', 'adminvhost.example.com')
        ->set('fullContent', $fullVhost."\n# client_max_body_size 512M;")
        ->call('saveConfig')
        ->assertSet('statusMessage', 'Nginx configuration validated with nginx -t, saved, and reloaded.');
});
