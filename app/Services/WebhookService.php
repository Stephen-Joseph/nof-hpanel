<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookSubscription;

class WebhookService
{
    /**
     * Dispatch an event to all matching webhook subscribers.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload, ?User $scopeUser = null): void
    {
        $query = WebhookSubscription::where('is_active', true);

        if ($scopeUser && ! $scopeUser->isAdmin()) {
            $query->where('user_id', $scopeUser->id);
        }

        $subscriptions = $query->get();

        foreach ($subscriptions as $subscription) {
            if ($subscription->subscribesTo($event)) {
                DispatchWebhookJob::dispatch($subscription, $event, $payload);
            }
        }
    }
}
