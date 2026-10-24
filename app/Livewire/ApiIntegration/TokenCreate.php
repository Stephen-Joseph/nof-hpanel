<?php

namespace App\Livewire\ApiIntegration;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class TokenCreate extends Component
{
    public string $name = '';

    /**
     * @var array<int, string>
     */
    public array $scopes = [];

    public string $expiry = '365'; // 'never', '30', '90', '365'

    public string $allowedIps = '';

    public ?string $generatedPlainTextToken = null;

    /**
     * @var array<string, string>
     */
    public array $availableScopes = [
        'accounts:read' => 'Read account details, status, and configuration',
        'accounts:write' => 'Create, suspend, unsuspend, upgrade, terminate accounts and passwords',
        'domains:write' => 'Add, delete, or configure addon and subdomain vhosts',
        'ssl:write' => 'Request, issue, and deploy SSL/TLS certificates',
        'usage:read' => 'Read bandwidth, disk, database, and quota statistics',
        'health:read' => 'Check cluster status and node agent responsiveness',
        'packages:read' => 'List available hosting plans and quota limits',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string'],
            'expiry' => ['required', 'in:never,30,90,365'],
            'allowedIps' => ['nullable', 'string'],
        ];
    }

    public function createToken(): void
    {
        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        // Process allowed IPs
        $ips = null;
        if (trim($this->allowedIps) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $this->allowedIps);
            $parsed = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    if (! filter_var($trimmed, FILTER_VALIDATE_IP) && ! preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $trimmed)) {
                        $this->addError('allowedIps', "Invalid IP or CIDR address: {$trimmed}");

                        return;
                    }
                    $parsed[] = $trimmed;
                }
            }
            if (! empty($parsed)) {
                $ips = array_values(array_unique($parsed));
            }
        }

        // Expiry calculation
        $expiresAt = match ($this->expiry) {
            '30' => now()->addDays(30),
            '90' => now()->addDays(90),
            '365' => now()->addDays(365),
            default => null,
        };

        // If all 7 scopes are selected, use ['*'] or keep full array
        $finalScopes = count($this->scopes) === count($this->availableScopes)
            ? ['*']
            : array_values($this->scopes);

        $result = ApiToken::createToken($user, $this->name, $finalScopes, $ips, $expiresAt);

        $this->generatedPlainTextToken = $result['plainTextToken'];
        $this->dispatch('open-modal', 'token-created-modal');
    }

    public function render(): View
    {
        return view('livewire.api-integration.token-create')
            ->layout('components.layouts.app');
    }
}

