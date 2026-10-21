<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class DispatchWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WebhookSubscription $subscription,
        public string $event,
        public array $payload
    ) {}

    public function handle(): void
    {
        $payloadJson = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payloadJson}", $this->subscription->secret);

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $this->subscription->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempts' => $this->attempts(),
        ]);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-HPanel-Event' => $this->event,
                    'X-HPanel-Timestamp' => (string) $timestamp,
                    'X-HPanel-Signature' => $signature,
                    'X-HPanel-Delivery-ID' => (string) $delivery->id,
                ])
                ->post($this->subscription->url, $this->payload);

            $delivery->update([
                'status_code' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000),
                'delivered_at' => $response->successful() ? now() : null,
                'error' => $response->successful() ? null : "HTTP {$response->status()}",
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException("Webhook delivery failed with HTTP status {$response->status()}");
            }
        } catch (Throwable $e) {
            $delivery->update([
                'error' => substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }
}
