<?php

use App\Enums\AgentJobStatus;
use App\Enums\ServerStatus;
use App\Livewire\Files\FileManager;
use App\Models\Account;
use App\Models\AgentJob;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.20']);
    $this->package = Package::factory()->create();

    $this->customer1 = User::factory()->customer()->create(['email' => 'alice@corp.com']);
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
});

test('customer can view file manager page for their account', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.list' && $payload['username'] === 'alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'current_path' => '/home/alicecorp',
                'rel_path' => '',
                'parent_path' => null,
                'entries' => [
                    [
                        'name' => 'public_html',
                        'path' => '/home/alicecorp/public_html',
                        'is_dir' => true,
                        'size' => 4096,
                        'mode' => '0755',
                        'mod_time' => now()->toIso8601String(),
                    ],
                    [
                        'name' => 'index.php',
                        'path' => '/home/alicecorp/public_html/index.php',
                        'is_dir' => false,
                        'size' => 120,
                        'mode' => '0644',
                        'mod_time' => now()->toIso8601String(),
                        'is_editable' => true,
                        'extension' => 'php',
                    ],
                ],
            ],
            'logs' => [],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    $this->actingAs($this->customer1)
        ->get(route('accounts.files', $this->account1))
        ->assertOk()
        ->assertSee('File Manager &middot; alicecorp.com', false)
        ->assertSee('public_html')
        ->assertSee('index.php');
});

test('cross-tenant customer is forbidden from accessing another account file manager', function () {
    $this->actingAs($this->customer1)
        ->get(route('accounts.files', $this->account2))
        ->assertForbidden();
});

test('reading and saving a file via file manager', function () {
    $mockClient = Mockery::mock(AgentClient::class);

    // Initial listing
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn([
            'success' => true,
            'data' => ['entries' => []],
            'logs' => [],
        ]);

    // Read action
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.read' && $payload['path'] === '/home/alicecorp/index.php';
        })
        ->andReturn([
            'success' => true,
            'data' => [
                'path' => '/home/alicecorp/index.php',
                'content' => '<?php echo "Original";',
                'size' => 24,
            ],
            'logs' => [],
        ]);

    // Write action
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.write' &&
                $payload['path'] === '/home/alicecorp/index.php' &&
                $payload['content'] === '<?php echo "Updated";';
        })
        ->andReturn([
            'success' => true,
            'data' => ['bytes_written' => 23],
            'logs' => [],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->call('editFile', '/home/alicecorp/index.php')
        ->assertSet('isEditing', true)
        ->assertSet('editingContent', '<?php echo "Original";')
        ->set('editingContent', '<?php echo "Updated";')
        ->call('saveFile')
        ->assertSet('isEditing', false)
        ->assertSee("File 'index.php' saved successfully.");
});

test('creating new file or directory', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.create' &&
                $payload['path'] === 'about.php' &&
                $payload['is_dir'] === false;
        })
        ->andReturn(['success' => true, 'data' => ['created' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->call('openCreateModal', false)
        ->set('newItemName', 'about.php')
        ->call('createItem')
        ->assertHasNoErrors()
        ->assertSee('File created successfully.');
});

test('renaming, deleting, and changing permissions', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    // Rename
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $a, $p) => $a === 'file.rename')
        ->andReturn(['success' => true, 'data' => ['renamed' => true], 'logs' => []]);

    // Chmod
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $a, $p) => $a === 'file.chmod' && $p['mode'] === '0755')
        ->andReturn(['success' => true, 'data' => ['mode' => '0755'], 'logs' => []]);

    // Delete
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $a, $p) => $a === 'file.delete' && $p['path'] === '/home/alicecorp/temp.txt')
        ->andReturn(['success' => true, 'data' => ['deleted' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->call('openRenameModal', '/home/alicecorp/old.txt')
        ->set('renamingNewName', 'new.txt')
        ->call('renameItem')
        ->assertSee('Item renamed.')
        ->call('openChmodModal', '/home/alicecorp/script.sh', '0644')
        ->set('chmodMode', '0755')
        ->call('chmodItem')
        ->assertSee('Permissions updated.')
        ->call('deleteItem', '/home/alicecorp/temp.txt')
        ->assertSee('Item deleted.');
});

test('upload rejects disallowed file extension', function () {
    Storage::fake('local');
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    $badFile = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->set('uploads', [$badFile])
        ->call('uploadFiles')
        ->assertHasErrors(['uploads']);

    expect(AgentJob::where('action', 'file.upload')->count())->toBe(0);
});

test('upload rejects oversized file exceeding max upload size', function () {
    Storage::fake('local');
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    // Set limit to 1 MB and upload a 2 MB file
    config()->set('hpanel.file_manager.max_upload_size_mb', 1);
    $hugeFile = UploadedFile::fake()->createWithContent('huge.zip', str_repeat('A', 1024 * 1024 * 2));

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->set('uploads', [$hugeFile])
        ->call('uploadFiles')
        ->assertHasErrors(['uploads.0']);

    expect(AgentJob::where('action', 'file.upload')->count())->toBe(0);
});

test('valid file upload creates agent job and executes file.upload', function () {
    Storage::fake('local');
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.upload'
                && $payload['username'] === 'alicecorp'
                && $payload['target_path'] === 'public_html'
                && $payload['filename'] === 'archive.zip'
                && ! empty($payload['content_base64']);
        })
        ->andReturn([
            'success' => true,
            'data' => ['filename' => 'archive.zip', 'bytes_written' => 256],
            'logs' => ['upload chunk written successfully'],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    $file = UploadedFile::fake()->createWithContent('archive.zip', 'dummy-zip-data-content-for-test');

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->set('uploadTargetFolder', 'public_html')
        ->set('uploads', [$file])
        ->call('uploadFiles')
        ->assertHasNoErrors()
        ->assertSee('1 file(s) uploaded successfully.');

    $job = AgentJob::where('action', 'file.upload')->first();
    expect($job)->not->toBeNull()
        ->and($job->status)->toBe(AgentJobStatus::COMPLETED)
        ->and($job->account_id)->toBe($this->account1->id);
});

test('extract here and extract to folder create agent job and execute file.extract', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    // Extract here call
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.extract'
                && $payload['username'] === 'alicecorp'
                && $payload['archive_path'] === '/home/alicecorp/site.tar.gz'
                && $payload['destination_dir'] === '/home/alicecorp';
        })
        ->andReturn([
            'success' => true,
            'data' => ['extracted' => true],
            'logs' => ['extracted tar.gz successfully'],
        ]);

    // Extract to folder call
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.extract'
                && $payload['username'] === 'alicecorp'
                && $payload['archive_path'] === '/home/alicecorp/backup.zip'
                && $payload['destination_dir'] === 'public_html/unpacked';
        })
        ->andReturn([
            'success' => true,
            'data' => ['extracted' => true],
            'logs' => ['extracted zip successfully'],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    $test = Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1]);

    // Test Extract Here
    $test->call('extractHere', '/home/alicecorp/site.tar.gz')
        ->assertSee('Archive extracted successfully.');

    expect(AgentJob::where('action', 'file.extract')->count())->toBe(1);

    // Test Extract to folder
    $test->call('openExtractModal', '/home/alicecorp/backup.zip')
        ->set('extractDestinationDir', 'public_html/unpacked')
        ->call('extractToFolder')
        ->assertHasNoErrors()
        ->assertSee('Archive extracted successfully.');

    expect(AgentJob::where('action', 'file.extract')->count())->toBe(2);
});

test('archive creates agent job and executes file.archive', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'file.archive'
                && $payload['username'] === 'alicecorp'
                && $payload['sources'] === ['public_html/index.php', 'public_html/style.css']
                && $payload['destination_zip'] === 'site-backup.zip';
        })
        ->andReturn([
            'success' => true,
            'data' => ['compressed' => true],
            'logs' => ['archive created successfully'],
        ]);

    app()->instance(AgentClient::class, $mockClient);

    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->set('selectedItems', ['public_html/index.php', 'public_html/style.css'])
        ->call('openArchiveModal')
        ->set('archiveDestinationName', 'site-backup.zip')
        ->call('createArchive')
        ->assertHasNoErrors()
        ->assertSee("Archive 'site-backup.zip' created successfully.");

    $job = AgentJob::where('action', 'file.archive')->first();
    expect($job)->not->toBeNull()
        ->and($job->status)->toBe(AgentJobStatus::COMPLETED)
        ->and($job->account_id)->toBe($this->account1->id);
});

test('authorization: account owner, parent reseller, and admin can manage files, unrelated customer is forbidden', function () {
    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.list')
        ->andReturn(['success' => true, 'data' => ['entries' => []], 'logs' => []]);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $a, $p) => $a === 'file.extract')
        ->andReturn(['success' => true, 'data' => ['extracted' => true], 'logs' => []]);

    app()->instance(AgentClient::class, $mockClient);

    // 1. Account Owner
    Livewire::actingAs($this->customer1)
        ->test(FileManager::class, ['account' => $this->account1])
        ->call('extractHere', '/home/alicecorp/test.zip')
        ->assertStatus(200);

    // 2. Admin
    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)
        ->test(FileManager::class, ['account' => $this->account1])
        ->call('extractHere', '/home/alicecorp/test.zip')
        ->assertStatus(200);

    // 3. Parent Reseller
    $reseller = User::factory()->reseller()->create();
    $childCustomer = User::factory()->customer()->create(['parent_id' => $reseller->id]);
    $resellerAccount = Account::factory()->create([
        'user_id' => $childCustomer->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'childuser',
    ]);

    Livewire::actingAs($reseller)
        ->test(FileManager::class, ['account' => $resellerAccount])
        ->call('extractHere', '/home/childuser/test.zip')
        ->assertStatus(200);

    // 4. Unrelated Customer is forbidden
    Livewire::actingAs($this->customer2)
        ->test(FileManager::class, ['account' => $this->account1])
        ->assertForbidden();
});
