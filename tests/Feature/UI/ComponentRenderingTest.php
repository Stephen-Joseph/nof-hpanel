<?php

use Illuminate\Support\Facades\Blade;

test('card component renders with title and slot content', function () {
    $rendered = Blade::render('<x-card title="Test Card Title">Card Body Content</x-card>');

    expect($rendered)
        ->toContain('Test Card Title')
        ->toContain('Card Body Content');
});

test('badge component renders with proper variant classes', function () {
    $renderedSuccess = Blade::render('<x-badge variant="success" dot>Active</x-badge>');
    $renderedDanger = Blade::render('<x-badge variant="danger">Failed</x-badge>');

    expect($renderedSuccess)
        ->toContain('Active')
        ->toContain('text-emerald-700')
        ->toContain('w-1.5 h-1.5 rounded-full bg-emerald-500');

    expect($renderedDanger)
        ->toContain('Failed')
        ->toContain('text-rose-700');
});

test('button component renders with variant and loading state', function () {
    $rendered = Blade::render('<x-button variant="primary" loading="submitForm">Save Changes</x-button>');

    expect($rendered)
        ->toContain('Save Changes')
        ->toContain('bg-gradient-primary')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="submitForm"');
});

test('form inputs render with labels, hints, and error placeholders', function () {
    $renderedInput = Blade::render('<x-input name="username" label="Linux Username" hint="3-16 chars" />');
    $renderedSelect = Blade::render('<x-select name="php_ver" label="PHP Version"><option value="8.3">8.3</option></x-select>');
    $renderedTextarea = Blade::render('<x-textarea name="ssh_key" label="Public Key" monospace />');

    expect($renderedInput)
        ->toContain('Linux Username')
        ->toContain('3-16 chars')
        ->toContain('id="username"');

    expect($renderedSelect)
        ->toContain('PHP Version')
        ->toContain('option value="8.3"');

    expect($renderedTextarea)
        ->toContain('Public Key')
        ->toContain('font-mono');
});

test('stat card renders metrics with progress bar', function () {
    $rendered = Blade::render('<x-stat-card title="Storage" value="2.5 GB" :progress="25" variant="indigo" />');

    expect($rendered)
        ->toContain('Storage')
        ->toContain('2.5 GB')
        ->toContain('style="width: 25%"');
});

test('code block component renders with title and copy button', function () {
    $rendered = Blade::render('<x-code-block title="nginx.conf" code="server { listen 80; }" />');

    expect($rendered)
        ->toContain('nginx.conf')
        ->toContain('server { listen 80; }')
        ->toContain('copyToClipboard');
});

test('empty state renders title and description', function () {
    $rendered = Blade::render('<x-empty-state title="No Backups Yet" description="Create a snapshot" />');

    expect($rendered)
        ->toContain('No Backups Yet')
        ->toContain('Create a snapshot');
});

test('theme toggle component renders with alpine data and toggle action', function () {
    $rendered = Blade::render('<x-ui.theme-toggle />');

    expect($rendered)
        ->toContain('x-data="{')
        ->toContain('localStorage.setItem(\'theme\', \'dark\')')
        ->toContain('@click="toggle()"')
        ->toContain('aria-label="Toggle light/dark theme"');
});

test('ui-kit demo route returns 200 in local or testing environment', function () {
    $response = $this->get(route('ui-kit'));

    $response->assertOk()
        ->assertSee('Design Tokens &amp; UI Kit', false)
        ->assertSee('--primary-500')
        ->assertSee('bg-gradient-primary');
});
