<?php

use App\Enums\ServerStatus;
use App\Livewire\Files\OnlineSftp;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear('online_sftp_user:1');
    RateLimiter::clear('online_sftp_user:2');
    RateLimiter::clear('online_sftp_user:3');

    $this->server = Server::factory()->create(['status' => ServerStatus::ACTIVE, 'private_ip' => '10.0.0.20']);
    $this->package = Package::factory()->create([
        'storage_mb' => 1000,
    ]);

    $this->reseller = User::factory()->reseller()->create(['email' => 'reseller@corp.com']);

    $this->customer1 = User::factory()->customer()->create([
        'email' => 'alice@corp.com',
        'parent_id' => $this->reseller->id,
    ]);

    $this->account1 = Account::factory()->create([
        'user_id' => $this->customer1->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'alicecorp',
        'primary_domain' => 'alicecorp.com',
        'current_storage_mb' => 50,
    ]);

    $this->customer2 = User::factory()->customer()->create(['email' => 'bob@corp.com']);
    $this->account2 = Account::factory()->create([
        'user_id' => $this->customer2->id,
        'server_id' => $this->server->id,
        'package_id' => $this->package->id,
        'linux_username' => 'bobinc',
        'primary_domain' => 'bobinc.com',
        'current_storage_mb' => 20,
    ]);
});

test('customer can access online sftp for their own account', function () {
    $this->actingAs($this->customer1);

    $response = $this->get(route('accounts.online_sftp', $this->account1));

    $response->assertOk();
    $response->assertSee('Online SFTP');
    $response->assertSee('alicecorp.com');
});

test('cross tenant access to online sftp returns 403', function () {
    $this->actingAs($this->customer2);

    $response = $this->get(route('accounts.online_sftp', $this->account1));

    $response->assertForbidden();
});

test('reseller can access online sftp for accounts in their subtree', function () {
    $this->actingAs($this->reseller);

    $response = $this->get(route('accounts.online_sftp', $this->account1));

    $response->assertOk();
    $response->assertSee('alicecorp.com');
});

test('session timeout locks online sftp and requires owner pin to unlock', function () {
    $this->actingAs($this->customer1);
    config(['hpanel.online_sftp.owner_pin' => '987654']);

    // Simulate session older than 30 minutes
    session(['sftp_unlocked_at' => now()->subMinutes(35)->timestamp]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')->zeroOrMoreTimes()->andReturn(['success' => true, 'data' => []]);
    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::test(OnlineSftp::class, ['account' => $this->account1])
        ->assertSet('isLocked', true)
        ->assertSee('Online SFTP Locked')
        ->set('pin', 'wrong-pin')
        ->call('unlockSession')
        ->assertHasErrors(['pin'])
        ->assertSet('isLocked', true)
        ->set('pin', '987654')
        ->call('unlockSession')
        ->assertHasNoErrors()
        ->assertSet('isLocked', false);
});

test('mutating actions record audit log without file contents', function () {
    $this->actingAs($this->customer1);
    session(['sftp_unlocked_at' => now()->timestamp]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'fs.create_file' && $payload['username'] === 'alicecorp';
        })
        ->once()
        ->andReturn(['success' => true, 'data' => ['created' => true]]);

    $mockClient->shouldReceive('execute')
        ->withArgs(function ($server, $action, $payload) {
            return $action === 'fs.list';
        })
        ->zeroOrMoreTimes()
        ->andReturn(['success' => true, 'data' => ['entries' => []]]);

    $this->app->instance(AgentClient::class, $mockClient);

    Livewire::test(OnlineSftp::class, ['account' => $this->account1])
        ->set('newFileName', 'secret_script.js')
        ->call('createFile');

    $log = AuditLog::where('target_id', $this->account1->id)
        ->where('action', 'fs.create_file')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($this->customer1->id);
    expect($log->new_values['path'])->toContain('secret_script.js');
    expect($log->new_values)->not->toHaveKey('content');
});

test('upload rejects oversize and blocked extensions', function () {
    $this->actingAs($this->customer1);
    session(['sftp_unlocked_at' => now()->timestamp]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')->zeroOrMoreTimes()->andReturn(['success' => true, 'data' => []]);
    $this->app->instance(AgentClient::class, $mockClient);

    // 1. Blocked extension (.exe)
    $blockedFile = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

    Livewire::test(OnlineSftp::class, ['account' => $this->account1])
        ->set('uploads', [$blockedFile])
        ->call('uploadFiles')
        ->assertHasErrors(['uploads']);

    // 2. Oversize file (> 1 MB when configured)
    config(['hpanel.file_manager.max_upload_size_mb' => 1]);
    $oversizeFile = UploadedFile::fake()->create('large.mp4', 2048);

    Livewire::test(OnlineSftp::class, ['account' => $this->account1])
        ->set('uploads', [$oversizeFile])
        ->call('uploadFiles')
        ->assertHasErrors(['uploads.0']);
});

test('smoke test flow: open page, create folder, upload file, edit, rename, delete', function () {
    $this->actingAs($this->customer1);
    session(['sftp_unlocked_at' => now()->timestamp]);

    $mockClient = Mockery::mock(AgentClient::class);
    $mockClient->shouldReceive('execute')
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.list')
        ->zeroOrMoreTimes()
        ->andReturn(['success' => true, 'data' => ['entries' => []]]);

    // 1. Create folder
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.mkdir' && str_ends_with($p['path'], 'my_app'))
        ->andReturn(['success' => true, 'data' => ['created' => true]]);

    // 2. Upload small html file
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.upload_begin' && $p['filename'] === 'index.html')
        ->andReturn(['success' => true, 'data' => ['session_id' => 'sess-123']]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.upload_chunk' && $p['session_id'] === 'sess-123')
        ->andReturn(['success' => true, 'data' => ['bytes_written' => 30]]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.upload_commit' && $p['session_id'] === 'sess-123')
        ->andReturn(['success' => true, 'data' => ['committed' => true]]);

    // 3. Edit file (read then write)
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.read_text' && str_ends_with($p['path'], 'index.html'))
        ->andReturn(['success' => true, 'data' => ['content' => '<h1>Hello</h1>']]);

    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.write_text' && str_ends_with($p['path'], 'index.html'))
        ->andReturn(['success' => true, 'data' => ['written' => true]]);

    // 4. Rename file
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.rename' && str_ends_with($p['target_path'], 'about.html'))
        ->andReturn(['success' => true, 'data' => ['renamed' => true]]);

    // 5. Delete file
    $mockClient->shouldReceive('execute')
        ->once()
        ->withArgs(fn ($s, $action, $p) => $action === 'fs.delete' && str_ends_with($p['path'], 'about.html'))
        ->andReturn(['success' => true, 'data' => ['deleted' => true]]);

    $this->app->instance(AgentClient::class, $mockClient);

    $htmlFile = UploadedFile::fake()->createWithContent('index.html', '<h1>Hello</h1>');

    Livewire::test(OnlineSftp::class, ['account' => $this->account1])
        ->set('newFolderName', 'my_app')
        ->call('createFolder')
        ->assertSee("Directory 'my_app' created successfully.")
        ->set('uploads', [$htmlFile])
        ->call('uploadFiles')
        ->assertSee('Uploaded 1 file(s) successfully.')
        ->call('openEditor', 'public_html/index.html')
        ->assertSet('isEditing', true)
        ->set('editingContent', '<h1>Hello Updated</h1>')
        ->call('saveEditor')
        ->assertSee("Saved 'index.html' successfully.")
        ->call('openRenameModal', 'public_html/index.html')
        ->set('renamingNewName', 'about.html')
        ->call('renameItem')
        ->assertSee("Renamed to 'about.html'.")
        ->call('openDeleteModal', 'public_html/about.html')
        ->call('confirmDelete')
        ->assertSee('Deleted 1 item(s).');
});

test('rate limit returns 429 when threshold exceeded', function () {
    $this->actingAs($this->customer1);

    config(['hpanel.online_sftp.rate_limit_per_minute' => 5]);
    RateLimiter::clear((string) $this->customer1->id);

    // Hit 5 times
    for ($i = 0; $i < 5; $i++) {
        $response = $this->get(route('accounts.online_sftp', $this->account1));
        $response->assertOk();
    }

    // 6th attempt should be blocked with 429 Too Many Requests
    $response = $this->get(route('accounts.online_sftp', $this->account1));
    $response->assertStatus(429);
});
