<?php

namespace App\Livewire\Domains;

use App\Enums\AppProfile;
use App\Enums\DomainType;
use App\Enums\WwwRedirect;
use App\Jobs\WriteVhostJob;
use App\Models\Account;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class DomainCreate extends Component
{
    public ?int $account_id = null;

    public string $domain = '';

    public string $type = 'addon';

    public string $document_root = '';

    public string $app_profile = 'php-generic';

    public string $php_version = '8.3';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $firstAccount = Account::visibleTo($user)->first();
        if ($firstAccount) {
            $this->account_id = $firstAccount->id;
            $this->updateDefaultDocumentRoot($firstAccount);
        }
    }

    public function updatedAccountId(): void
    {
        if ($this->account_id) {
            $account = Account::find($this->account_id);
            if ($account) {
                $this->updateDefaultDocumentRoot($account);
            }
        }
    }

    public function updatedDomain(): void
    {
        if ($this->account_id && ! empty($this->domain)) {
            $account = Account::find($this->account_id);
            if ($account) {
                $cleanDomain = strtolower(trim($this->domain));
                if ($this->type === 'subdomain') {
                    $this->document_root = "/home/{$account->linux_username}/subdomains/{$cleanDomain}";
                } else {
                    $this->document_root = "/home/{$account->linux_username}/public_html/{$cleanDomain}";
                }
            }
        }
    }

    protected function updateDefaultDocumentRoot(Account $account): void
    {
        if ($this->type === 'subdomain') {
            $this->document_root = "/home/{$account->linux_username}/subdomains";
        } else {
            $this->document_root = "/home/{$account->linux_username}/public_html";
        }
    }

    public function save(): ?RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $account = Account::with('package')->findOrFail($this->account_id);
        $this->authorize('view', $account);

        $cleanDomain = strtolower(trim($this->domain));

        $this->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'domain' => [
                'required',
                'string',
                'regex:'.Account::DOMAIN_REGEX,
                'unique:domains,domain',
            ],
            'type' => ['required', Rule::in(['addon', 'subdomain'])],
            'document_root' => ['required', 'string', 'max:255'],
            'app_profile' => ['required', Rule::in(['php-generic', 'laravel', 'wordpress', 'static-spa'])],
            'php_version' => ['required', 'string'],
        ]);

        // Enforce document root isolation strictly inside account home, disallowing traversal
        $expectedHome = "/home/{$account->linux_username}";
        if (str_contains($this->document_root, '..') || str_contains($this->document_root, "\0")) {
            $this->addError('document_root', 'Document root contains illegal path traversal characters.');

            return null;
        }

        if ($this->document_root !== $expectedHome && ! str_starts_with($this->document_root, $expectedHome.'/')) {
            $this->addError('document_root', "Document root must be located inside {$expectedHome}/");

            return null;
        }

        // Enforce Package Limits
        if ($this->type === 'addon') {
            $existingCount = Domain::where('account_id', $account->id)->whereIn('type', ['primary', 'addon'])->count();
            if ($existingCount >= $account->package->max_websites) {
                $this->addError('domain', "Package website limit reached (Max: {$account->package->max_websites} websites).");

                return null;
            }
        } elseif ($this->type === 'subdomain') {
            $existingSubdomains = Domain::where('account_id', $account->id)->where('type', 'subdomain')->count();
            if ($existingSubdomains >= $account->package->max_subdomains) {
                $this->addError('domain', "Package subdomain limit reached (Max: {$account->package->max_subdomains} subdomains).");

                return null;
            }
        }

        $domainModel = Domain::create([
            'account_id' => $account->id,
            'domain' => $cleanDomain,
            'type' => DomainType::from($this->type),
            'document_root' => $this->document_root,
            'app_profile' => AppProfile::from($this->app_profile),
            'php_version' => $this->php_version,
            'force_https' => false,
            'www_redirect' => WwwRedirect::NONE,
            'is_active' => true,
        ]);

        WriteVhostJob::dispatch($domainModel);

        session()->flash('status', "Domain '{$domainModel->domain}' created and virtual host configuration dispatched.");

        return redirect()->route('domains.index');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $accounts = Account::visibleTo($user)->orderBy('primary_domain')->get();

        return view('livewire.domains.domain-create', [
            'accounts' => $accounts,
        ]);
    }
}
