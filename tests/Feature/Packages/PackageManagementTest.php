<?php

use App\Livewire\Packages\PackageForm;
use App\Livewire\Packages\PackageList;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

test('package list page renders and filters packages', function () {
    $admin = User::factory()->admin()->create();
    $pkg1 = Package::factory()->create(['name' => 'Alpha Cloud']);
    $pkg2 = Package::factory()->create(['name' => 'Beta Hosting']);

    $this->actingAs($admin)->get('/packages')->assertStatus(200);

    Livewire::actingAs($admin)
        ->test(PackageList::class)
        ->assertSee('Alpha Cloud')
        ->assertSee('Beta Hosting')
        ->set('search', 'Alpha')
        ->assertSee('Alpha Cloud')
        ->assertDontSee('Beta Hosting');
});

test('admin can create package via livewire and audit log is recorded', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(PackageForm::class)
        ->set('name', 'Super Plan')
        ->set('storage_mb', 20480)
        ->set('inodes', 150000)
        ->set('bandwidth_gb', 200)
        ->set('max_websites', 3)
        ->set('max_subdomains', 10)
        ->set('max_databases', 5)
        ->set('max_db_users', 5)
        ->set('max_ftp_accounts', 5)
        ->set('max_cron_jobs', 5)
        ->set('php_workers', 10)
        ->set('php_memory_mb', 512)
        ->set('allowed_php_versions', ['8.2', '8.3'])
        ->set('backups_retained', 5)
        ->set('ssh_enabled', true)
        ->set('git_enabled', true)
        ->call('save')
        ->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', [
        'name' => 'Super Plan',
        'reseller_owner_id' => null,
        'ssh_enabled' => true,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'package.created',
        'user_id' => $admin->id,
    ]);
});

test('reseller package creation sets reseller owner id automatically', function () {
    $reseller = User::factory()->reseller()->create();

    Livewire::actingAs($reseller)
        ->test(PackageForm::class)
        ->set('name', 'Reseller Special')
        ->set('storage_mb', 5120)
        ->set('inodes', 50000)
        ->set('bandwidth_gb', 50)
        ->set('max_websites', 1)
        ->set('max_subdomains', 2)
        ->set('max_databases', 1)
        ->set('max_db_users', 1)
        ->set('max_ftp_accounts', 1)
        ->set('max_cron_jobs', 1)
        ->set('php_workers', 4)
        ->set('php_memory_mb', 256)
        ->set('allowed_php_versions', ['8.3'])
        ->set('backups_retained', 2)
        ->call('save')
        ->assertRedirect(route('packages.index'));

    $this->assertDatabaseHas('packages', [
        'name' => 'Reseller Special',
        'reseller_owner_id' => $reseller->id,
    ]);
});

test('package can be deleted with audit trail', function () {
    $admin = User::factory()->admin()->create();
    $pkg = Package::factory()->create();

    Livewire::actingAs($admin)
        ->test(PackageList::class)
        ->call('deletePackage', $pkg->id);

    $this->assertDatabaseMissing('packages', ['id' => $pkg->id]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'package.deleted',
        'user_id' => $admin->id,
    ]);
});
