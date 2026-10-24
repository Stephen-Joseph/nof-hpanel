<?php

namespace App\Livewire\ApiIntegration;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class WebhookManager extends Component
{
    use WithPagination;

    public string $url = '';

    /**
     * @var array<int, string>
     */
    public array $events = ['account.created', 'account.suspended', 'account.terminated'];

    public ?string $newSecret = null;

    public ?string $newSecretUrl = null;

    /**
     * @var array<string, string>
     */
    public array $availableEvents = [
        'account.created' => 'Fires when a new hosting account is successfully created',
        'account.suspended' => 'Fires when an account is suspended due to billing or abuse',
        'account.unsuspended' => 'Fires when a suspended account is reactivated',
        'account.terminated' => 'Fires when an account is terminated and unlinked',
        'domain.created' => 'Fires when an addon domain or subdomain is registered',
        'ssl.issued' => 'Fires when a Let\'s Encrypt SSL certificate is issued or renewed',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'url' => ['required', 'url', 'regex:/^https:\/\//i'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'url.regex' => 'Webhook URL must use secure HTTPS protocol (https://...).',
        ];
    }

    public function createEndpoint(): void
    {
        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        $secret = bin2hex(random_bytes(24));

        $subscription = WebhookSubscription::create([
            'user_id' => $user->id,
            'url' => $this->url,
            'secret' => $secret,
            'events' => array_values($this->events),
            'is_active' => true,
        ]);

        $this->newSecret = $secret;
        $this->newSecretUrl = $subscription->url;
        $this->url = '';
        $this->events = ['account.created', 'account.suspended', 'account.terminated'];

        $this->dispatch('open-modal', 'secret-modal');
        session()->flash('status', 'Webhook endpoint registered successfully.');
    }

    public function regenerateSecret(int $id): void
    {
        /** @var User $user */
        $user = Auth::user();

        $query = WebhookSubscription::where('id', $id);
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $subscription = $query->firstOrFail();
        $secret = bin2hex(random_bytes(24));

        $subscription->update(['secret' => $secret]);

        $this->newSecret = $secret;
        $this->newSecretUrl = $subscription->url;
        $this->dispatch('open-modal', 'secret-modal');
        session()->flash('status', "Secret regenerated for {$subscription->url}.");
    }

    public function toggleActive(int $id): void
    {
        /** @var User $user */
        $user = Auth::user();

        $query = WebhookSubscription::where('id', $id);
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $subscription = $query->firstOrFail();
        $subscription->update(['is_active' => ! $subscription->is_active]);

        $state = $subscription->is_active ? 'activated' : 'deactivated';
        session()->flash('status', "Webhook endpoint {$state}.");
    }

    public function deleteEndpoint(int $id): void
    {
        /** @var User $user */
        $user = Auth::user();

        $query = WebhookSubscription::where('id', $id);
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $subscription = $query->firstOrFail();
        $subscription->delete();

        session()->flash('status', 'Webhook endpoint deleted.');
    }

    public function sendTestEvent(int $id): void
    {
        /** @var User $user */
        $user = Auth::user();

        $query = WebhookSubscription::where('id', $id);
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $subscription = $query->firstOrFail();

        $testPayload = [
            'event' => 'test.ping',
            'timestamp' => now()->toIso8601String(),
            'message' => 'HPanel Webhook Test Event',
            'environment' => config('app.env'),
        ];

        DispatchWebhookJob::dispatch($subscription, 'test.ping', $testPayload);

        session()->flash('status', "Test webhook dispatched to {$subscription->url}.");
    }

    public function retryDelivery(int $deliveryId): void
    {
        /** @var User $user */
        $user = Auth::user();

        $query = WebhookDelivery::with('subscription')->where('id', $deliveryId);
        if (! $user->isAdmin()) {
            $query->whereHas('subscription', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        $delivery = $query->firstOrFail();

        DispatchWebhookJob::dispatch($delivery->subscription, $delivery->event, $delivery->payload);

        session()->flash('status', "Retrying delivery #{$delivery->id} for event '{$delivery->event}'.");
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $endpointsQuery = WebhookSubscription::with('user')->orderBy('id', 'desc');
        if (! $user->isAdmin()) {
            $endpointsQuery->where('user_id', $user->id);
        }
        $endpoints = $endpointsQuery->get();

        $deliveriesQuery = WebhookDelivery::with('subscription')->orderBy('id', 'desc');
        if (! $user->isAdmin()) {
            $deliveriesQuery->whereHas('subscription', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }
        $deliveries = $deliveriesQuery->paginate(10);

        return view('livewire.api-integration.webhook-manager', [
            'endpoints' => $endpoints,
            'deliveries' => $deliveries,
        ])->layout('components.layouts.app');
    }
}

