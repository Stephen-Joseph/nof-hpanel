<?php

namespace App\Livewire\Admin;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class ApiTokenManager extends Component
{
    public string $tokenName = '';

    public string $selectedScopes = '*';

    public string $ipAllowlist = '';

    public ?int $expiresInDays = 365;

    public ?string $generatedPlainTextToken = null;

    public string $webhookUrl = '';

    public string $webhookEvents = 'account.created,account.suspended,account.terminated';

    public function createToken(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $this->validate([
            'tokenName' => ['required', 'string', 'max:100'],
            'selectedScopes' => ['required', 'string'],
            'ipAllowlist' => ['nullable', 'string'],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        /** @var User $admin */
        $admin = Auth::user();

        $scopes = array_map('trim', explode(',', $this->selectedScopes));
        $ips = null;
        if (! empty(trim($this->ipAllowlist))) {
            $ips = array_filter(array_map('trim', explode(',', $this->ipAllowlist)));
        }

        $expiresAt = $this->expiresInDays ? now()->addDays($this->expiresInDays) : null;

        $result = ApiToken::createToken($admin, $this->tokenName, $scopes, $ips, $expiresAt);

        $this->generatedPlainTextToken = $result['plainTextToken'];
        $this->tokenName = '';
        $this->ipAllowlist = '';
        $this->selectedScopes = '*';

        session()->flash('status', 'API token created successfully. Copy the token now; it will not be displayed again.');
    }

    public function revokeToken(int $id): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $token = ApiToken::findOrFail($id);
        $token->revoke();

        session()->flash('status', "API token '{$token->name}' revoked.");
    }

    public function createWebhook(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $this->validate([
            'webhookUrl' => ['required', 'url'],
            'webhookEvents' => ['required', 'string'],
        ]);

        /** @var User $admin */
        $admin = Auth::user();
        $events = array_map('trim', explode(',', $this->webhookEvents));

        WebhookSubscription::create([
            'user_id' => $admin->id,
            'url' => $this->webhookUrl,
            'secret' => bin2hex(random_bytes(24)),
            'events' => $events,
            'is_active' => true,
        ]);

        $this->webhookUrl = '';
        session()->flash('status', 'Webhook subscription registered.');
    }

    public function toggleWebhook(int $id): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $webhook = WebhookSubscription::findOrFail($id);
        $webhook->update(['is_active' => ! $webhook->is_active]);
    }

    public function render(): View
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $tokens = ApiToken::with('user')->orderBy('id', 'desc')->get();
        $webhooks = WebhookSubscription::with('user')->orderBy('id', 'desc')->get();
        $deliveries = WebhookDelivery::with('subscription')->orderBy('id', 'desc')->take(10)->get();

        return view('livewire.admin.api-token-manager', [
            'tokens' => $tokens,
            'webhooks' => $webhooks,
            'deliveries' => $deliveries,
        ]);
    }
}
