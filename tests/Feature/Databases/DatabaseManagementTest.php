<?php

use App\Enums\ServerStatus;
use App\Jobs\CreateDatabaseJob;
use App\Jobs\CreateDatabaseUserJob;
use App\Jobs\DropDatabaseJob;
use App\Jobs\DropDatabaseUserJob;
use App\Jobs\ExportDatabaseJob;
use App\Jobs\GrantDatabasePrivilegesJob;
use App\Jobs\RevokeDatabasePrivilegesJob;
use App\Jobs\UpdateDatabaseUserPasswordJob;
use App\Livewire\Databases\DatabaseManager;
use App\Models\Account;
use App\Models\Database;
use App\Models\DatabaseSsoToken;
use App\Models\DatabaseUser;
use App\Models\DatabaseUserGrant;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create([
        'max_databases' => 2,
        'max_db_users' => 2,
    ]);

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);

    $this->admin = User::factory()->admin()->create();
});

test('customer can view their account databases page', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.databases', $this->account1))
        ->assertOk()
        ->assertSee('MariaDB Databases');
});

test('customer cannot view another account databases page', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.databases', $this->account2))
        ->assertForbidden();
});

test('admin can view any account databases page', function () {
    $this->actingAs($this->admin)
        ->get(route('accounts.databases', $this->account1))
        ->assertOk();
});

test('customer can create database within package limits', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->set('dbSuffix', 'blog')
        ->call('createDatabase')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('databases', [
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_blog',
        'charset' => 'utf8mb4',
    ]);

    Queue::assertPushed(CreateDatabaseJob::class, function ($job) {
        return $job->database->name === 'alicecorp_blog';
    });
});

test('database creation enforces package limits', function () {
    Queue::fake();

    // Package allows 2 databases
    Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_db1',
    ]);
    Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_db2',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->set('dbSuffix', 'db3')
        ->call('createDatabase')
        ->assertHasErrors(['dbSuffix']);

    $this->assertDatabaseMissing('databases', [
        'name' => 'alicecorp_db3',
    ]);

    Queue::assertNotPushed(CreateDatabaseJob::class);
});

test('cannot create duplicate database name', function () {
    Queue::fake();

    Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_app',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->set('dbSuffix', 'app')
        ->call('createDatabase')
        ->assertHasErrors(['dbSuffix']);

    Queue::assertNotPushed(CreateDatabaseJob::class);
});

test('customer can drop their database', function () {
    Queue::fake();

    $db = Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_olddb',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('confirmDropDatabase', $db->id)
        ->call('dropDatabase')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('databases', ['id' => $db->id]);

    Queue::assertPushed(DropDatabaseJob::class, function ($job) {
        return $job->databaseName === 'alicecorp_olddb';
    });
});

test('customer cannot drop another accounts database', function () {
    Queue::fake();

    $dbOther = Database::create([
        'account_id' => $this->account2->id,
        'name' => 'bobinc_privatedb',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('confirmDropDatabase', $dbOther->id)
        ->call('dropDatabase')
        ->assertStatus(404);

    $this->assertDatabaseHas('databases', ['id' => $dbOther->id]);
    Queue::assertNotPushed(DropDatabaseJob::class);
});

test('customer can create database user within limits', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->set('userSuffix', 'wpusr')
        ->set('userPassword', 'StrongPassw0rd!2026')
        ->set('userHost', 'localhost')
        ->call('createDatabaseUser')
        ->assertHasNoErrors()
        ->assertSet('generatedPasswordNotice', 'StrongPassw0rd!2026');

    $this->assertDatabaseHas('database_users', [
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_wpusr',
        'remote_host' => 'localhost',
    ]);

    Queue::assertPushed(CreateDatabaseUserJob::class, function ($job) {
        return $job->databaseUser->username === 'alicecorp_wpusr' && $job->password === 'StrongPassw0rd!2026';
    });
});

test('database user creation enforces package limits', function () {
    Queue::fake();

    // Package allows 2 users
    DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_u1',
        'remote_host' => 'localhost',
    ]);
    DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_u2',
        'remote_host' => 'localhost',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->set('userSuffix', 'u3')
        ->set('userPassword', 'StrongPassw0rd!2026')
        ->set('userHost', 'localhost')
        ->call('createDatabaseUser')
        ->assertHasErrors(['userSuffix']);

    Queue::assertNotPushed(CreateDatabaseUserJob::class);
});

test('customer can drop database user', function () {
    Queue::fake();

    $user = DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_temp',
        'remote_host' => 'localhost',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('confirmDropUser', $user->id)
        ->call('dropDatabaseUser')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('database_users', ['id' => $user->id]);

    Queue::assertPushed(DropDatabaseUserJob::class, function ($job) {
        return $job->dbUser === 'alicecorp_temp';
    });
});

test('customer can update database user password', function () {
    Queue::fake();

    $user = DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_app',
        'remote_host' => 'localhost',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openPasswordModal', $user->id)
        ->set('newPassword', 'BrandNewPassw0rd#2026')
        ->call('updatePassword')
        ->assertHasNoErrors();

    Queue::assertPushed(UpdateDatabaseUserPasswordJob::class, function ($job) use ($user) {
        return $job->databaseUser->id === $user->id && $job->newPassword === 'BrandNewPassw0rd#2026';
    });
});

test('customer can grant and revoke privileges on database to user', function () {
    Queue::fake();

    $db = Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_blog',
    ]);

    $dbUser = DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_writer',
        'remote_host' => 'localhost',
    ]);

    // Grant privileges
    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openGrantModal', $db->id, $dbUser->id)
        ->set('selectedPrivileges', ['SELECT', 'INSERT', 'UPDATE'])
        ->call('saveGrant')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('database_user_grants', [
        'database_id' => $db->id,
        'database_user_id' => $dbUser->id,
    ]);

    Queue::assertPushed(GrantDatabasePrivilegesJob::class);

    $grant = DatabaseUserGrant::where('database_id', $db->id)->where('database_user_id', $dbUser->id)->firstOrFail();

    // Revoke privileges
    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('revokeGrant', $grant->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('database_user_grants', ['id' => $grant->id]);
    Queue::assertPushed(RevokeDatabasePrivilegesJob::class);
});

test('customer can export database dump', function () {
    Queue::fake();

    $db = Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_exportme',
    ]);

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('exportDatabase', $db->id)
        ->assertHasNoErrors();

    Queue::assertPushed(ExportDatabaseJob::class, function ($job) use ($db) {
        return $job->database->id === $db->id;
    });
});

test('one-click phpMyAdmin SSO generates single-use token and consumes safely', function () {
    $dbUser = DatabaseUser::create([
        'account_id' => $this->account1->id,
        'username' => 'alicecorp_admin',
        'remote_host' => 'localhost',
    ]);

    // Step 1: Launch SSO
    $response = $this->actingAs($this->customer1)
        ->get(route('accounts.databases.sso', ['account' => $this->account1, 'databaseUser' => $dbUser]));

    $response->assertRedirect();
    $redirectUrl = $response->headers->get('Location');
    $this->assertStringContainsString('/pma-sso/', $redirectUrl);

    $tokenRecord = DatabaseSsoToken::where('database_user_id', $dbUser->id)->latest()->first();
    $this->assertNotNull($tokenRecord);
    $this->assertTrue($tokenRecord->isValid());

    // Step 2: First consumption succeeds
    $consumeResponse = $this->actingAs($this->customer1)
        ->get(route('pma.sso', ['token' => $tokenRecord->token]));

    $consumeResponse->assertOk()
        ->assertSee('phpMyAdmin SSO Authenticated')
        ->assertSee('alicecorp_admin');

    $tokenRecord->refresh();
    $this->assertNotNull($tokenRecord->used_at);
    $this->assertFalse($tokenRecord->isValid());

    // Step 3: Second consumption is rejected (single use token protection)
    $secondResponse = $this->actingAs($this->customer1)
        ->get(route('pma.sso', ['token' => $tokenRecord->token]));

    $secondResponse->assertForbidden();
});

test('customer cannot launch SSO for another accounts user', function () {
    $dbUserOther = DatabaseUser::create([
        'account_id' => $this->account2->id,
        'username' => 'bobinc_admin',
        'remote_host' => 'localhost',
    ]);

    $this->actingAs($this->customer1)
        ->get(route('accounts.databases.sso', ['account' => $this->account1, 'databaseUser' => $dbUserOther]))
        ->assertForbidden();
});
