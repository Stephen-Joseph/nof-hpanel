<?php

use App\Enums\AgentJobStatus;
use App\Enums\ServerStatus;
use App\Jobs\ImportDatabaseJob;
use App\Livewire\Databases\DatabaseManager;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Database;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create([
        'status' => ServerStatus::ACTIVE,
        'hostname' => 'node1.example.com',
        'private_ip' => '10.0.0.10',
    ]);

    $this->package = Package::factory()->create([
        'max_databases' => 5,
        'max_db_users' => 5,
    ]);

    $this->customer1 = User::factory()->customer()->create();
    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
    ]);

    $this->db1 = Database::create([
        'account_id' => $this->account1->id,
        'name' => 'alicecorp_wp',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->customer2 = User::factory()->customer()->create();
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
    ]);

    $this->db2 = Database::create([
        'account_id' => $this->account2->id,
        'name' => 'bobinc_shop',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
});

test('customer can queue SQL import from home directory path', function () {
    Queue::fake();

    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openImportModal', $this->db1->id)
        ->assertSet('showingImportModal', true)
        ->set('importSource', 'home')
        ->set('importHomeFilePath', 'backups/2026_dump.sql.gz')
        ->call('importDatabase')
        ->assertHasNoErrors()
        ->assertSet('showingImportModal', false);

    Queue::assertPushed(ImportDatabaseJob::class, function ($job) {
        return $job->database->id === $this->db1->id
            && $job->inputFile === '/home/alicecorp/backups/2026_dump.sql.gz';
    });
});

test('import validates home path against directory traversal and invalid extension', function () {
    Queue::fake();

    // 1. Directory traversal rejected
    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openImportModal', $this->db1->id)
        ->set('importSource', 'home')
        ->set('importHomeFilePath', '../../etc/passwd')
        ->call('importDatabase')
        ->assertHasErrors(['importHomeFilePath']);

    // 2. Non-SQL file rejected
    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openImportModal', $this->db1->id)
        ->set('importSource', 'home')
        ->set('importHomeFilePath', 'backups/script.php')
        ->call('importDatabase')
        ->assertHasErrors(['importHomeFilePath']);

    Queue::assertNothingPushed();
});

test('ImportDatabaseJob executes agent action and truncates error to 500 characters', function () {
    $longError = str_repeat('Access denied on database other_db. ', 25); // > 800 chars
    $this->assertTrue(strlen($longError) > 500);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $server->id === $this->server->id
                && $action === 'db.import'
                && $payload['username'] === 'alicecorp'
                && $payload['db_name'] === 'alicecorp_wp'
                && $payload['input_file'] === '/home/alicecorp/backups/dump.sql';
        })
        ->andThrow(new \Exception($longError));

    $job = new ImportDatabaseJob($this->db1, '/home/alicecorp/backups/dump.sql');

    try {
        $job->handle($mockClient);
        $this->fail('Expected exception to be thrown');
    } catch (\Throwable $e) {
        // Exception expected
    }

    $agentJob = AgentJob::where('action', 'db.import')
        ->where('account_id', $this->account1->id)
        ->latest()
        ->first();

    $this->assertNotNull($agentJob);
    $this->assertEquals(AgentJobStatus::FAILED, $agentJob->status);
    $this->assertLessThanOrEqual(500, strlen($agentJob->error_message));
});

test('cross-tenant SQL import is denied', function () {
    // Customer 1 cannot open import modal or act on Customer 2's database
    Livewire::actingAs($this->customer1)
        ->test(DatabaseManager::class, ['account' => $this->account1])
        ->call('openImportModal', $this->db2->id)
        ->assertForbidden();
});
