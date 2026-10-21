<?php

use App\Enums\ServerStatus;
use App\Livewire\Files\FileManager;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
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

