<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Jobs\InstallCustomCertificateJob;
use App\Jobs\IssueSslCertificateJob;
use App\Jobs\RevokeSslCertificateJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\Domains\DomainSsl;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.12']);
    $this->package = Package::factory()->create();

    $this->customer1 = User::factory()->customer()->create(['email' => 'alice@alicecorp.com']);
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);
    $this->domain1 = Domain::factory()->verified()->create([
        'account_id' => $this->account1->id,
        'domain' => 'alicecorp.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/alicecorp/public_html',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);
    $this->domain2 = Domain::factory()->create([
        'account_id' => $this->account2->id,
        'domain' => 'bobinc.com',
        'type' => DomainType::PRIMARY,
        'document_root' => '/home/bobinc/public_html',
    ]);
});

test('customer can view SSL management page for their domain', function () {
    $this->actingAs($this->customer1)
        ->get(route('domains.ssl', $this->domain1))
        ->assertOk()
        ->assertSee('SSL / TLS Certificates: alicecorp.com');
});

test('cross-tenant customer is forbidden from accessing another domain SSL page', function () {
    $this->actingAs($this->customer1)
        ->get(route('domains.ssl', $this->domain2))
        ->assertForbidden();
});

test('customer can request lets encrypt certificate and job is dispatched', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DomainSsl::class, ['domain' => $this->domain1])
        ->set('email', 'admin@alicecorp.com')
        ->call('requestLetsEncrypt')
        ->assertSee("Let's Encrypt certificate issuance enqueued");

    Queue::assertPushed(IssueSslCertificateJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id && $job->email === 'admin@alicecorp.com';
    });
});

test('issue ssl job handles agent success and updates certificate and vhost', function () {
    Queue::fake([WriteVhostJob::class]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'ssl.issue' && $payload['domain'] === 'alicecorp.com';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'domain' => 'alicecorp.com',
                'cert_path' => '/etc/letsencrypt/live/alicecorp.com/fullchain.pem',
                'key_path' => '/etc/letsencrypt/live/alicecorp.com/privkey.pem',
                'issuer' => "Let's Encrypt Authority X3",
                'valid_from' => now()->toIso8601String(),
                'valid_to' => now()->addDays(90)->toIso8601String(),
                'days_remaining' => 90,
            ],
            'logs' => ['[certbot] certificate generated successfully'],
        ]);

    $webhooks = app(WebhookService::class);
    $job = new IssueSslCertificateJob($this->domain1, 'admin@alicecorp.com');
    $job->handle($mockClient, $webhooks);

    $this->assertDatabaseHas('certificates', [
        'domain_id' => $this->domain1->id,
        'status' => CertificateStatus::ACTIVE->value,
        'type' => CertificateType::LETS_ENCRYPT->value,
        'issuer' => "Let's Encrypt Authority X3",
    ]);

    Queue::assertPushed(WriteVhostJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });
});

test('issue ssl job handles agent failure gracefully', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->andReturn([
            'success' => false,
            'error' => 'DNS problem: NXDOMAIN looking up A for alicecorp.com',
            'logs' => ['Failed authorization challenge'],
        ]);

    $webhooks = app(WebhookService::class);
    $job = new IssueSslCertificateJob($this->domain1, 'admin@alicecorp.com');
    $job->handle($mockClient, $webhooks);

    $this->assertDatabaseHas('certificates', [
        'domain_id' => $this->domain1->id,
        'status' => CertificateStatus::FAILED->value,
        'last_error' => 'DNS problem: NXDOMAIN looking up A for alicecorp.com',
    ]);
});

test('custom ssl certificate upload validates matching private key and dispatches install job', function () {
    Queue::fake();

    $validCert = "-----BEGIN CERTIFICATE-----\nMIICgzCCAeygAwIBAgIBADANBgkqhkiG9w0BAQUFADBdMRYwFAYDVQQDDA1hbGlj\nZWNvcnAuY29tMQswCQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEhMB8G\nA1UECgwYSW50ZXJuZXQgV2lkZ2l0cyBQdHkgTHRkMB4XDTI2MTAyMTEzNDkwOVoX\nDTI3MTAyMTEzNDkwOVowXTEWMBQGA1UEAwwNYWxpY2Vjb3JwLmNvbTELMAkGA1UE\nBhMCQVUxEzARBgNVBAgMClNvbWUtU3RhdGUxITAfBgNVBAoMGEludGVybmV0IFdp\nZGdpdHMgUHR5IEx0ZDCBnzANBgkqhkiG9w0BAQEFAAOBjQAwgYkCgYEA0x+nIoCc\nA1Z46iZlnXLZ3jh0kgSGiJ1i/WwnV70Lnhkh75kc09LU1N+4HbF1t8mDPiZorjxk\nEx4PZMq8ipP8qFA3g6PzIkzB2czuvkY1gPDg7mgYxXJXGHKxnw/qMIW+gECw05Jo\nO7Rjeh7LitpeEB04pHmBrb/1r6q3xShu+IMCAwEAAaNTMFEwHQYDVR0OBBYEFN/A\nSwxFS0HYgsudCWe4sHmahrlVMB8GA1UdIwQYMBaAFN/ASwxFS0HYgsudCWe4sHma\nhrlVMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQEFBQADgYEADC3z7jEjlVue\nfkZbY6PrsM0TIAx42lLsjGhVwGQxX3AxJtPfdyo4Vp5SjelxHK5fMEcX2cTtjCs7\nn+bn3BWFpTvdy91YEm9627T5fm0O4hISVfJlrIsqNOF1wI84yIitWo7Itory6yC+\nUjailvwyPMPtgzgWBDwiuLtbj0RVsEA=\n-----END CERTIFICATE-----\n";
    $matchingKey = "-----BEGIN PRIVATE KEY-----\nMIICdwIBADANBgkqhkiG9w0BAQEFAASCAmEwggJdAgEAAoGBANMfpyKAnANWeOom\nZZ1y2d44dJIEhoidYv1sJ1e9C54ZIe+ZHNPS1NTfuB2xdbfJgz4maK48ZBMeD2TK\nvIqT/KhQN4Oj8yJMwdnM7r5GNYDw4O5oGMVyVxhysZ8P6jCFvoBAsNOSaDu0Y3oe\ny4raXhAdOKR5ga2/9a+qt8UobviDAgMBAAECgYBBOyFv1p7dpSUc65ov6KGKOJVD\n3GQY59ADDxykzkM9qPRETGGMzTJ3R1xiyEbf70I+vK1RUFV3Vq1Y7g8NHiZM5rrV\nqlzY+Tgqqf2p7Rc8oPnM7e5OYK9ZYuEW0DLc1gsD5xnnjz2GHwq86+Ubc6z93pWQ\nUWYl+/omnq5QIxSg6QJBAPyuwkPT6yBVEWWqI5Knl6WdLLORmtSaH0N0AG95cyFY\n0+LsXW3Ev2h7VJT+zbg/pINGHjUafwNwUNPyhoFAmI8CQQDV5TfluPH+DNO0Ma3r\n1k5unt+B10DhQMv/OxHZBSaeI0ZqNc6DV+DSxGgOPkcRGKX9gJpuv/d5WWEapkKR\nOVLNAkEA4bOMDJ//VtsFdiT6L2YJm8IACsmVXi/omwzD0s7N2syIPxrX/VOPkVkQ\nXSIYSWp3nVaFZvOq3jx1xkvMfyle2wJBAJXe5iuwSt8XXb8EkDiPEKWx7CmuCWfL\nXySW3wqQ8V4cmVoSGYEuMfl8ZOyV9nWkL6pQUeUH3P7KAaAKntnisIECQCDnZsKr\nypdyFCxk7/y/rVkEGST1bwRtg8hAqyUQbIrscczPeeIyQb84/GtHYcXtFWxUrFF6\nvZbIZI0ZPZehJZ8=\n-----END PRIVATE KEY-----\n";
    $mismatchedKey = "-----BEGIN PRIVATE KEY-----\nMIICeAIBADANBgkqhkiG9w0BAQEFAASCAmIwggJeAgEAAoGBALrZzYdZanTVMZ+R\nFSqLna2uFYowi8D2BqBvi7JW6R77whF0U0FVzoY1RbVsI55eIomQRnY6Teep3aA1\nAnPdmDOEoqsakQonYOfDiQk4VL3oKXKfHGvlSUD1ePq67J4cGYKO/iZYJ/eTkfGS\n9uN8qYl8tmEvPIFYpwyrefWsQV7JAgMBAAECgYEAkvqBEdbkRsLtJfeaDr2Eavpl\nNy64wDDHRax41vBGsHiZT+GRG/PDYQ7Gps9JtWmXAV7cDmQOpAyaqUsJgtrsvT/z\n1DVaN8jjvPCwNhQq1y25OVQmZdv3uxlwwGXMIBgC7Fiuot38koBSukI18gdCkMZX\n0G9AIghYd98gb3PVIAECQQDxcj6JaHq7cZiNbz1AXp5tQTTz9uMPKyii+TVY0bI/\n6Gs00vpVbuB2mBVunvhWgU0oBoRr/HbQ5jidODA+i13hAkEAxh0Ynkav+3NVJfxd\ngZIdIifE1Rl8v2ffWR1Hap+9Hur9yjktiXq3d15Q/nFm865kpiny+YOmYF8ZYZAK\nSzSN6QJBAKhJd7swfnMNz0WtZiTqjkQacf0ldMeKzm0hhARfTH1s0uRjtSY8/OjM\nQgvzc6Zfw1aFjGDz/CDIhmdW1ZGSdCECQQCi4mtKPr4UFFguuhY7IOH5Ig9wUdtC\nF+oHf84m+RzqHbT6Hha1kQLlDtOyz5QgyAKSp6zQvw/87lOU5nPAL355AkBebs/j\ncrwZE57nMsTT8oSx0C1n3N7HO8WMZzhcgXRhXdZD071AwVfeRNgN34vrZzrTgX0t\npd29DdXeF3vNLFNV\n-----END PRIVATE KEY-----\n";

    // Test mismatched key rejection
    Livewire::actingAs($this->customer1)
        ->test(DomainSsl::class, ['domain' => $this->domain1])
        ->set('customCert', $validCert)
        ->set('customKey', $mismatchedKey)
        ->call('installCustomCertificate')
        ->assertHasErrors(['customKey']);

    Queue::assertNothingPushed();

    // Test matching key acceptance
    Livewire::actingAs($this->customer1)
        ->test(DomainSsl::class, ['domain' => $this->domain1])
        ->set('customCert', $validCert)
        ->set('customKey', $matchingKey)
        ->call('installCustomCertificate')
        ->assertHasNoErrors()
        ->assertSee('Custom SSL certificate installation enqueued');

    Queue::assertPushed(InstallCustomCertificateJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });
});

test('install custom certificate job updates certificate and vhost', function () {
    Queue::fake([WriteVhostJob::class]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'ssl.install_custom' && $payload['domain'] === 'alicecorp.com';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'domain' => 'alicecorp.com',
                'cert_path' => '/etc/ssl/panel/alicecorp.com/certificate.crt',
                'key_path' => '/etc/ssl/panel/alicecorp.com/private.key',
                'issuer' => 'DigiCert TLS RSA SHA256 2020 CA1',
                'valid_from' => now()->toIso8601String(),
                'valid_to' => now()->addYear()->toIso8601String(),
            ],
            'logs' => ['Custom certificate written'],
        ]);

    $webhooks = app(WebhookService::class);
    $job = new InstallCustomCertificateJob($this->domain1, 'cert-content', 'key-content');
    $job->handle($mockClient, $webhooks);

    $this->assertDatabaseHas('certificates', [
        'domain_id' => $this->domain1->id,
        'status' => CertificateStatus::ACTIVE->value,
        'type' => CertificateType::CUSTOM->value,
        'issuer' => 'DigiCert TLS RSA SHA256 2020 CA1',
    ]);

    Queue::assertPushed(WriteVhostJob::class);
});

test('toggling security options updates model and dispatches vhost rewrite', function () {
    Queue::fake();

    Certificate::factory()->create([
        'domain_id' => $this->domain1->id,
        'status' => CertificateStatus::ACTIVE,
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DomainSsl::class, ['domain' => $this->domain1])
        ->set('force_https', true)
        ->set('hsts_enabled', true)
        ->set('auto_renew', false)
        ->call('saveToggles')
        ->assertSee('SSL and security settings updated successfully');

    $this->domain1->refresh();
    $cert = $this->domain1->certificate;

    expect($this->domain1->force_https)->toBeTrue()
        ->and($cert->hsts_enabled)->toBeTrue()
        ->and($cert->auto_renew)->toBeFalse();

    Queue::assertPushed(WriteVhostJob::class);
});

test('revoking certificate sets status to revoked and dispatches job', function () {
    Queue::fake([WriteVhostJob::class]);

    $cert = Certificate::factory()->create([
        'domain_id' => $this->domain1->id,
        'status' => CertificateStatus::ACTIVE,
    ]);
    $this->domain1->update(['force_https' => true]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'ssl.revoke' && $payload['domain'] === 'alicecorp.com';
        })
        ->andReturn([
            'success' => true,
            'data' => ['domain' => 'alicecorp.com', 'revoked' => true],
            'logs' => ['Certificate revoked'],
        ]);

    $job = new RevokeSslCertificateJob($this->domain1);
    $job->handle($mockClient);

    $cert->refresh();
    $this->domain1->refresh();

    expect($cert->status)->toEqual(CertificateStatus::REVOKED)
        ->and($this->domain1->force_https)->toBeFalse();

    Queue::assertPushed(WriteVhostJob::class);
});

test('scheduled renew expiring command picks up certificates expiring within 30 days', function () {
    Queue::fake();

    // Expiring in 10 days -> should renew
    $expiringCert = Certificate::factory()->create([
        'domain_id' => $this->domain1->id,
        'type' => CertificateType::LETS_ENCRYPT,
        'status' => CertificateStatus::ACTIVE,
        'auto_renew' => true,
        'valid_to' => now()->addDays(10),
    ]);

    // Expiring in 60 days -> should NOT renew
    $healthyCert = Certificate::factory()->create([
        'domain_id' => $this->domain2->id,
        'type' => CertificateType::LETS_ENCRYPT,
        'status' => CertificateStatus::ACTIVE,
        'auto_renew' => true,
        'valid_to' => now()->addDays(60),
    ]);

    Artisan::call('ssl:renew-expiring');

    Queue::assertPushed(IssueSslCertificateJob::class, 1);
    Queue::assertPushed(IssueSslCertificateJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });
});

test('api endpoint post /api/v1/ssl/issue dispatches ssl issue job for authorized customer', function () {
    Queue::fake();

    $tokenData = ApiToken::createToken($this->customer1, 'Customer Token');
    $token = $tokenData['plainTextToken'];

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/ssl/issue', [
            'domain' => 'alicecorp.com',
        ])
        ->assertStatus(202)
        ->assertJson([
            'status' => 'issuing',
            'domain' => 'alicecorp.com',
        ]);

    Queue::assertPushed(IssueSslCertificateJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });

    // Cross-tenant attempt is forbidden
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/ssl/issue', [
            'domain' => 'bobinc.com',
        ])
        ->assertForbidden();
});
