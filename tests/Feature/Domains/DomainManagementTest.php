<?php

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\ServerStatus;
use App\Enums\WwwRedirect;
use App\Jobs\RemoveVhostJob;
use App\Jobs\WriteVhostJob;
use App\Livewire\Domains\DnsHelper;
use App\Livewire\Domains\DomainCreate;
use App\Livewire\Domains\DomainList;
use App\Livewire\Domains\DomainRedirects;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Redirect;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.5']);
    $this->package = Package::factory()->create([
        'max_websites' => 3,
        'max_subdomains' => 5,
    ]);

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);
    $this->domain1 = Domain::factory()->create([
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

test('customer can view only their own domains in domain list', function () {
    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->assertSee('alicecorp.com')
        ->assertDontSee('bobinc.com');
});

test('customer can create an addon domain and dispatch write vhost job', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DomainCreate::class)
        ->set('account_id', $this->account1->id)
        ->set('domain', 'shop.alicecorp.com')
        ->set('type', 'addon')
        ->set('document_root', '/home/alicecorp/public_html/shop')
        ->set('app_profile', 'laravel')
        ->set('php_version', '8.4')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('domains.index'));

    $this->assertDatabaseHas('domains', [
        'account_id' => $this->account1->id,
        'domain' => 'shop.alicecorp.com',
        'type' => DomainType::ADDON->value,
        'document_root' => '/home/alicecorp/public_html/shop',
        'app_profile' => AppProfile::LARAVEL->value,
        'php_version' => '8.4',
    ]);

    Queue::assertPushed(WriteVhostJob::class, function ($job) {
        return $job->domain->domain === 'shop.alicecorp.com';
    });
});

test('domain creation enforces strict document root path containment inside account home', function () {
    Queue::fake();

    // Traversal attack
    Livewire::actingAs($this->customer1)
        ->test(DomainCreate::class)
        ->set('account_id', $this->account1->id)
        ->set('domain', 'badtraversal.com')
        ->set('type', 'addon')
        ->set('document_root', '/home/alicecorp/../bobinc/public_html')
        ->call('save')
        ->assertHasErrors(['document_root']);

    // Outside home directory
    Livewire::actingAs($this->customer1)
        ->test(DomainCreate::class)
        ->set('account_id', $this->account1->id)
        ->set('domain', 'outsidehome.com')
        ->set('type', 'addon')
        ->set('document_root', '/var/www/html')
        ->call('save')
        ->assertHasErrors(['document_root']);

    Queue::assertNothingPushed();
});

test('domain creation enforces package max websites limit', function () {
    Queue::fake();

    $limitedPackage = Package::factory()->create([
        'max_websites' => 1, // Only primary domain allowed
    ]);
    $this->account1->update(['package_id' => $limitedPackage->id]);

    Livewire::actingAs($this->customer1)
        ->test(DomainCreate::class)
        ->set('account_id', $this->account1->id)
        ->set('domain', 'secondsite.com')
        ->set('type', 'addon')
        ->set('document_root', '/home/alicecorp/public_html/secondsite')
        ->call('save')
        ->assertHasErrors(['domain']);

    Queue::assertNothingPushed();
});

test('domain creation enforces package max subdomains limit', function () {
    Queue::fake();

    $noSubdomainPackage = Package::factory()->create([
        'max_subdomains' => 0,
    ]);
    $this->account1->update(['package_id' => $noSubdomainPackage->id]);

    Livewire::actingAs($this->customer1)
        ->test(DomainCreate::class)
        ->set('account_id', $this->account1->id)
        ->set('domain', 'api.alicecorp.com')
        ->set('type', 'subdomain')
        ->set('document_root', '/home/alicecorp/subdomains/api')
        ->call('save')
        ->assertHasErrors(['domain']);

    Queue::assertNothingPushed();
});

test('primary domain cannot be deleted via domain list', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('deleteDomain', $this->domain1->id)
        ->assertSee('The primary domain of an account cannot be deleted');

    $this->assertDatabaseHas('domains', ['id' => $this->domain1->id]);
    Queue::assertNothingPushed();
});

test('addon domain can be deleted and dispatches remove vhost job', function () {
    Queue::fake();

    $addon = Domain::factory()->addon()->create([
        'account_id' => $this->account1->id,
        'domain' => 'blog.alicecorp.com',
        'document_root' => '/home/alicecorp/public_html/blog',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('deleteDomain', $addon->id)
        ->assertSee('Domain \'blog.alicecorp.com\' removed and virtual host purged.');

    $this->assertDatabaseMissing('domains', ['id' => $addon->id]);

    Queue::assertPushed(RemoveVhostJob::class, function ($job) {
        return $job->domainName === 'blog.alicecorp.com' && $job->account->id === $this->account1->id;
    });
});

test('domain redirect settings update and dispatch write vhost job', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DomainRedirects::class, ['domain' => $this->domain1])
        ->set('force_https', true)
        ->set('www_redirect', 'force_www')
        ->call('saveSettings')
        ->assertSee('Domain redirect and SSL settings updated.');

    $this->domain1->refresh();
    expect($this->domain1->force_https)->toBeTrue()
        ->and($this->domain1->www_redirect)->toEqual(WwwRedirect::FORCE_WWW);

    Queue::assertPushed(WriteVhostJob::class);
});

test('custom url redirect rules can be added and deleted', function () {
    Queue::fake();

    $component = Livewire::actingAs($this->customer1)
        ->test(DomainRedirects::class, ['domain' => $this->domain1])
        ->set('newSource', '/old-contact')
        ->set('newTarget', '/contact-us')
        ->set('newStatusCode', 301)
        ->call('addRedirect')
        ->assertSee('Custom URL redirect rule added.');

    $redirect = Redirect::where('domain_id', $this->domain1->id)->where('source_path', '/old-contact')->first();
    expect($redirect)->not->toBeNull()
        ->and($redirect->target_url)->toEqual('/contact-us')
        ->and($redirect->status_code)->toEqual(301);

    Queue::assertPushed(WriteVhostJob::class);

    // Now delete rule
    $component->call('deleteRedirect', $redirect->id)
        ->assertSee('Redirect rule deleted.');

    $this->assertDatabaseMissing('redirects', ['id' => $redirect->id]);
});

test('dns helper inspects records and detects mismatch or resolution', function () {
    Livewire::actingAs($this->customer1)
        ->test(DnsHelper::class, ['domain' => $this->domain1])
        ->call('checkDns')
        ->assertSet('hasChecked', true)
        ->assertSee('Required DNS Records');
});

test('cross tenant domain manipulation is prohibited', function () {
    // Customer 1 cannot access Customer 2 redirects page
    $this->actingAs($this->customer1)
        ->get(route('domains.redirects', $this->domain2))
        ->assertForbidden();

    // Customer 1 cannot access Customer 2 DNS helper
    $this->actingAs($this->customer1)
        ->get(route('domains.dns', $this->domain2))
        ->assertForbidden();

    // Customer 1 cannot delete Customer 2 domain
    $addonBob = Domain::factory()->addon()->create([
        'account_id' => $this->account2->id,
        'domain' => 'promo.bobinc.com',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('deleteDomain', $addonBob->id)
        ->assertForbidden();
});

test('customer can choose and update application profile in domain list', function () {
    Queue::fake();

    $this->domain1->update(['app_profile' => AppProfile::LARAVEL]);

    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('openProfileModal', $this->domain1->id)
        ->assertSet('showingProfileModal', true)
        ->assertSet('editingDomainId', $this->domain1->id)
        ->assertSet('selectedProfile', 'laravel')
        ->set('selectedProfile', 'php-generic')
        ->call('updateAppProfile')
        ->assertSet('showingProfileModal', false)
        ->assertHasNoErrors();

    $this->domain1->refresh();
    expect($this->domain1->app_profile)->toEqual(AppProfile::PHP_GENERIC);

    Queue::assertPushed(WriteVhostJob::class, function ($job) {
        return $job->domain->id === $this->domain1->id;
    });
});

test('cross-tenant application profile update is forbidden in domain list', function () {
    Livewire::actingAs($this->customer1)
        ->test(DomainList::class)
        ->call('openProfileModal', $this->domain2->id)
        ->assertForbidden();
});

test('customer can update application profile in php manager', function () {
    Queue::fake();

    $this->domain1->update(['app_profile' => AppProfile::LARAVEL]);

    Livewire::actingAs($this->customer1)
        ->test(\App\Livewire\Domains\PhpManager::class, ['domain' => $this->domain1])
        ->set('app_profile', 'php-generic')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('PHP version updated to');

    $this->domain1->refresh();
    expect($this->domain1->app_profile)->toEqual(AppProfile::PHP_GENERIC);

    Queue::assertPushed(\App\Jobs\SwitchDomainPhpVersionJob::class);
});

test('domains regenerate-vhost command updates domain to php-generic and writes vhost', function () {
    Queue::fake();

    $domain = Domain::factory()->create([
        'account_id' => $this->account1->id,
        'domain' => 'copyplusdtp.in',
        'type' => DomainType::ADDON,
        'app_profile' => AppProfile::LARAVEL,
        'document_root' => '/home/alicecorp/copyplusdtp.in',
    ]);

    $this->artisan('domains:regenerate-vhost copyplusdtp.in php-generic')
        ->assertExitCode(0)
        ->expectsOutputToContain("Domain 'copyplusdtp.in' updated to 'php-generic' and virtual host regenerated.");

    $domain->refresh();
    expect($domain->app_profile)->toEqual(AppProfile::PHP_GENERIC);
});
