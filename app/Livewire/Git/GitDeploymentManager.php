<?php

namespace App\Livewire\Git;

use App\Jobs\ExecuteGitDeployJob;
use App\Jobs\RollbackGitDeployJob;
use App\Models\Account;
use App\Models\GitDeployment;
use App\Models\GitRelease;
use App\Services\SshKeyGenerator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class GitDeploymentManager extends Component
{
    public Account $account;

    // Form fields
    public string $name = '';

    public ?int $domain_id = null;

    public string $repository_url = '';

    public string $branch = 'main';

    public string $deploy_path = '';

    /** @var list<string> */
    public array $build_steps = [];

    public bool $auto_deploy = true;

    // Modals & UI state
    public bool $showCreateModal = false;

    public bool $showKeyModal = false;

    public string $activePublicKey = '';

    public string $activeWebhookUrl = '';

    public bool $showLogsModal = false;

    public string $activeLogOutput = '';

    public string $activeReleaseTag = '';

    public ?int $selectedDeploymentId = null;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['package', 'server', 'domains', 'gitDeployments.releases']);
        $this->deploy_path = "/home/{$account->linux_username}/public_html";
    }

    public function updatedDomainId(?int $value): void
    {
        if ($value) {
            $domain = $this->account->domains->firstWhere('id', $value);
            if ($domain) {
                $this->deploy_path = $domain->document_root;
            }
        }
    }

    public function openCreateModal(): void
    {
        if (! $this->account->canUseGit()) {
            session()->flash('error', 'Git deployment is not enabled on this hosting package.');

            return;
        }

        $this->name = '';
        $this->repository_url = '';
        $this->branch = 'main';
        $this->build_steps = ['composer_install'];
        $this->auto_deploy = true;
        $this->showCreateModal = true;
    }

    public function createDeployment(): void
    {
        $this->authorize('view', $this->account);

        if (! $this->account->canUseGit()) {
            session()->flash('error', 'Git deployment is not enabled on this hosting package.');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:64'],
            'repository_url' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\.\/]{1,100}$/'],
            'deploy_path' => ['required', 'string', 'starts_with:/home/'.$this->account->linux_username.'/'],
            'domain_id' => ['nullable', 'exists:domains,id'],
            'build_steps' => ['array'],
            'auto_deploy' => ['boolean'],
        ]);

        $keyPair = SshKeyGenerator::generate('hpanel-deploy-'.Str::slug($this->name));
        $webhookSecret = Str::random(40);

        /** @var GitDeployment $deployment */
        $deployment = $this->account->gitDeployments()->create([
            'name' => $this->name,
            'domain_id' => $this->domain_id,
            'repository_url' => trim($this->repository_url),
            'branch' => trim($this->branch),
            'deploy_path' => trim($this->deploy_path),
            'public_key' => $keyPair['public_key'],
            'private_key' => $keyPair['private_key'],
            'webhook_secret' => $webhookSecret,
            'build_steps' => $this->build_steps,
            'auto_deploy' => $this->auto_deploy,
        ]);

        $this->showCreateModal = false;
        $this->showDeployKey($deployment->id);
        $this->account->load('gitDeployments.releases');

        session()->flash('success', "Git deployment '{$deployment->name}' created successfully!");
    }

    public function showDeployKey(int $deploymentId): void
    {
        $deployment = $this->account->gitDeployments()->findOrFail($deploymentId);
        $this->selectedDeploymentId = $deployment->id;
        $this->activePublicKey = $deployment->public_key;
        $this->activeWebhookUrl = route('api.v1.git-deploy', ['secret' => $deployment->webhook_secret]);
        $this->showKeyModal = true;
    }

    public function deployNow(int $deploymentId): void
    {
        $this->authorize('view', $this->account);
        $deployment = $this->account->gitDeployments()->findOrFail($deploymentId);

        ExecuteGitDeployJob::dispatch($deployment);

        session()->flash('success', "Deployment triggered for '{$deployment->name}'. Processing in background...");
        $this->account->load('gitDeployments.releases');
    }

    public function rollback(int $deploymentId, string $releaseTag): void
    {
        $this->authorize('view', $this->account);
        $deployment = $this->account->gitDeployments()->findOrFail($deploymentId);

        RollbackGitDeployJob::dispatch($deployment, $releaseTag);

        session()->flash('success', "Rollback initiated to release {$releaseTag}.");
        $this->account->load('gitDeployments.releases');
    }

    public function viewLogs(int $releaseId): void
    {
        /** @var GitRelease $release */
        $release = GitRelease::whereHas('deployment', function ($q) {
            $q->where('account_id', $this->account->id);
        })->findOrFail($releaseId);

        $this->activeReleaseTag = $release->release_tag;
        $this->activeLogOutput = $release->log_output ?? 'No log output recorded.';
        $this->showLogsModal = true;
    }

    public function deleteDeployment(int $deploymentId): void
    {
        $this->authorize('view', $this->account);
        $deployment = $this->account->gitDeployments()->findOrFail($deploymentId);
        $deployment->delete();

        session()->flash('success', 'Git deployment removed.');
        $this->account->load('gitDeployments.releases');
    }

    public function render(): View
    {
        return view('livewire.git.git-deployment-manager');
    }
}
