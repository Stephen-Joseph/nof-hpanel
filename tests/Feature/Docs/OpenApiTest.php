<?php

use App\Models\User;

test('docs openapi yaml redirects guests to login', function () {
    $response = $this->get('/docs/openapi.yaml');

    $response->assertRedirect(route('login'));
});

test('docs openapi yaml returns 200 with application/yaml for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/docs/openapi.yaml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/yaml')
        ->assertSee('openapi: 3.1.0');
});

test('ui-kit route returns 404 when environment is production', function () {
    // In production, the route is not registered
    $this->app->detectEnvironment(fn () => 'production');

    // Make request to /ui-kit
    $response = $this->get('/ui-kit');

    $response->assertNotFound();
});

