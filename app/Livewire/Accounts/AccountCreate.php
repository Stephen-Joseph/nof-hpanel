<?php

namespace App\Livewire\Accounts;

use App\Enums\AccountStatus;
use App\Enums\ServerStatus;
use App\Enums\UserRole;
use App\Jobs\ProvisionAccount;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class AccountCreate extends Component
{
    public ?int $user_id = null;

    public ?int $package_id = null;

    public ?int $server_id = null;

    public string $linux_username = '';

    public string $primary_domain = '';

    public function mount(): void
    {
        $this->authorize('create', Account::class);

        /** @var User $actor */
        $actor = Auth::user();

        // Default to first active server
        $defaultServer = Server::where('status', ServerStatus::ACTIVE)->first();
        if ($defaultServer) {
            $this->server_id = $defaultServer->id;
        }

        // Default to first available package
        $defaultPackage = Package::availableFor($actor)->first();
        if ($defaultPackage) {
            $this->package_id = $defaultPackage->id;
        }

        // If reseller, default to first customer in subtree if any
        if ($actor->isReseller()) {
            $firstCustomer = $actor->children()->first();
            if ($firstCustomer) {
                $this->user_id = $firstCustomer->id;
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'server_id' => ['required', 'integer', 'exists:servers,id'],
            'linux_username' => [
                'required',
                'string',
                'min:3',
                'max:16',
                'regex:'.Account::USERNAME_REGEX,
                'unique:accounts,linux_username',
                Rule::notIn([
                    'root', 'bin', 'daemon', 'sys', 'adm', 'sync', 'games', 'man', 'lp', 'mail',
                    'news', 'uucp', 'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats', 'nobody',
                    'systemd', 'mysql', 'nginx', 'redis', 'ssh', 'sshd', 'ftp', 'panel', 'agent',
                ]),
            ],
            'primary_domain' => [
                'required',
                'string',
                'regex:'.Account::DOMAIN_REGEX,
                'unique:accounts,primary_domain',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'linux_username.regex' => 'Linux username must begin with a lowercase letter and contain only 3 to 16 lowercase letters and numbers.',
            'primary_domain.regex' => 'Primary domain must be a valid RFC-compliant domain name (e.g., example.com).',
        ];
    }

    public function updatedPrimaryDomain(string $value): void
    {
        $domain = Str::lower(trim($value));
        $this->primary_domain = $domain;

        // Auto-suggest Linux username if empty
        if ($this->linux_username === '' && $domain !== '') {
            $cleaned = preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]);
            if ($cleaned !== '') {
                $suggested = Str::substr($cleaned, 0, 12);
                if (preg_match('/^[a-z]/', $suggested)) {
                    $this->linux_username = $suggested;
                }
            }
        }
    }

    public function save(AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $validated = $this->validate();

        /** @var User $actor */
        $actor = Auth::user();

        // Enforce reseller tenancy: Reseller can only create accounts for users in their subtree
        if ($actor->isReseller()) {
            /** @var User|null $targetUser */
            $targetUser = User::find($validated['user_id']);
            if (! $targetUser || (! $targetUser->isDescendantOf($actor) && $targetUser->id !== $actor->id)) {
                abort(403, 'You can only create accounts for customers in your own reseller subtree.');
            }
        }

        // Verify server is eligible
        /** @var Server $server */
        $server = Server::findOrFail($validated['server_id']);
        if (! $server->isAvailableForNewAccounts()) {
            $this->addError('server_id', 'The selected server is not currently accepting new accounts.');

            return redirect()->back();
        }

        // Create the account row with pending status
        $account = Account::create([
            'user_id' => $validated['user_id'],
            'package_id' => $validated['package_id'],
            'server_id' => $validated['server_id'],
            'linux_username' => $validated['linux_username'],
            'primary_domain' => $validated['primary_domain'],
            'status' => AccountStatus::PENDING,
            'suspended_reason' => null,
        ]);

        // Audit log
        $audit->log('account.created', $account, null, $account->toArray(), $actor);

        // Dispatch ProvisionAccount job stub
        ProvisionAccount::dispatch($account);

        session()->flash('status', "Account {$account->linux_username} successfully created and enqueued for provisioning.");

        return redirect()->route('accounts.show', $account);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();

        // Customers list according to tenancy
        $customers = $actor->isAdmin()
            ? User::where('role', UserRole::CUSTOMER)->orderBy('name')->get()
            : User::whereIn('id', $actor->getAllDescendantIds())
                ->where('role', UserRole::CUSTOMER)
                ->orderBy('name')
                ->get();

        $packages = Package::availableFor($actor)->orderBy('name')->get();
        $servers = Server::where('status', ServerStatus::ACTIVE)->orderBy('name')->get();

        return view('livewire.accounts.account-create', [
            'customers' => $customers,
            'packages' => $packages,
            'servers' => $servers,
        ]);
    }
}
