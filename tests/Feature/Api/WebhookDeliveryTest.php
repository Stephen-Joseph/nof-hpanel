<?php

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\WebhookService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    $this->subscription = WebhookSubscription::create([
        'user_id' => $this->admin->id,
        'url' => 'https://billing.example.org/webhook-receiver',
        'secret' => 'whsec_test_secret_123',
        'events' => ['account.created', 'account.suspended'],
        'is_active' => true,
    ]);
});

test('WebhookService dispatches job for subscribed events', function () {
    Queue::fake();

    $service = new WebhookService;
    $service->dispatch('account.created', ['account_id' => 42]);

    Queue::assertPushed(DispatchWebhookJob::class, function ($job) {
        return $job->subscription->id === $this->subscription->id
            && $job->event === 'account.created'
            && $job->payload === ['account_id' => 42];
    });
});

test('WebhookService skips unsubscribed events', function () {
    Queue::fake();

    $service = new WebhookService;
    $service->dispatch('account.unsuspend_unmatched_event', ['account_id' => 42]);

    Queue::assertNotPushed(DispatchWebhookJob::class);
});

test('DispatchWebhookJob signs payload with HMAC-SHA256 and records delivery', function () {
    Http::fake([
        'https://billing.example.org/webhook-receiver' => Http::response(['received' => true], 200),
    ]);

    $payload = ['account_id' => 42, 'status' => 'created'];

    $job = new DispatchWebhookJob($this->subscription, 'account.created', $payload);
    $job->handle();

    Http::assertSent(function (Request $request) use ($payload) {
        $signature = $request->header('X-HPanel-Signature')[0] ?? '';
        $timestamp = $request->header('X-HPanel-Timestamp')[0] ?? '';
        $event = $request->header('X-HPanel-Event')[0] ?? '';

        $expectedSig = hash_hmac(
            'sha256',
            "{$timestamp}.".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'whsec_test_secret_123'
        );

        return $request->url() === 'https://billing.example.org/webhook-receiver'
            && $event === 'account.created'
            && hash_equals($expectedSig, $signature);
    });

    $this->assertDatabaseHas('webhook_deliveries', [
        'webhook_subscription_id' => $this->subscription->id,
        'event' => 'account.created',
        'status_code' => 200,
    ]);
});
