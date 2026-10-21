<?php

namespace App\Livewire\Domains;

use App\Enums\WwwRedirect;
use App\Jobs\WriteVhostJob;
use App\Models\Domain;
use App\Models\Redirect;
use Illuminate\View\View;
use Livewire\Component;

class DomainRedirects extends Component
{
    public Domain $domain;

    public bool $force_https = false;

    public string $www_redirect = 'none';

    public string $newSource = '';

    public string $newTarget = '';

    public int $newStatusCode = 301;

    public function mount(Domain $domain): void
    {
        $this->authorize('update', $domain);

        $this->domain = $domain->load(['account.server', 'redirects']);
        $this->force_https = $this->domain->force_https;
        $this->www_redirect = $this->domain->www_redirect->value;
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->domain);

        $this->domain->update([
            'force_https' => $this->force_https,
            'www_redirect' => WwwRedirect::from($this->www_redirect),
        ]);

        WriteVhostJob::dispatch($this->domain);

        session()->flash('status', 'Domain redirect and SSL settings updated.');
    }

    public function addRedirect(): void
    {
        $this->authorize('update', $this->domain);

        $this->validate([
            'newSource' => ['required', 'string', 'starts_with:/', 'max:255'],
            'newTarget' => ['required', 'string', 'max:255'],
            'newStatusCode' => ['required', 'in:301,302'],
        ]);

        Redirect::create([
            'domain_id' => $this->domain->id,
            'source_path' => $this->newSource,
            'target_url' => $this->newTarget,
            'status_code' => $this->newStatusCode,
            'is_active' => true,
        ]);

        $this->newSource = '';
        $this->newTarget = '';
        $this->newStatusCode = 301;

        WriteVhostJob::dispatch($this->domain);

        session()->flash('status', 'Custom URL redirect rule added.');
    }

    public function deleteRedirect(int $id): void
    {
        $this->authorize('update', $this->domain);

        $redirect = $this->domain->redirects()->findOrFail($id);
        $redirect->delete();

        WriteVhostJob::dispatch($this->domain);

        session()->flash('status', 'Redirect rule deleted.');
    }

    public function render(): View
    {
        $this->domain->refresh();

        return view('livewire.domains.domain-redirects', [
            'redirects' => $this->domain->redirects,
        ]);
    }
}
