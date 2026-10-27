<?php

namespace App\Livewire\Domains;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\AgentClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class WebServerSettings extends Component
{
    public Domain $domain;

    /**
     * @var array<int, array{source: string, target: string, code: int}>
     */
    public array $redirects = [];

    public string $custom_error_404 = '';

    public string $custom_error_403 = '';

    public bool $clean_urls = true;

    public bool $force_https = false;

    public int $max_upload_body_size_mb = 32;

    public string $cache_lifetime_static = 'none';

    /**
     * @var array<int, string>
     */
    public array $default_index = ['index.php', 'index.html'];

    public string $generatedCustomConfig = '';

    public bool $customConfigExists = false;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public ?string $nginxTestOutput = null;

    public function mount(Domain $domain): void
    {
        $this->authorize('update', $domain);

        $this->domain = $domain->load(['account.server', 'account.package']);

        // Load existing stored settings if present
        $stored = $this->domain->web_server_settings ?? [];
        if (! empty($stored)) {
            $this->redirects = $stored['redirects'] ?? [];
            $this->custom_error_404 = (string) ($stored['custom_error_404'] ?? '');
            $this->custom_error_403 = (string) ($stored['custom_error_403'] ?? '');
            $this->clean_urls = (bool) ($stored['clean_urls'] ?? true);
            $this->force_https = (bool) ($stored['force_https'] ?? $this->domain->force_https ?? false);
            $this->max_upload_body_size_mb = (int) ($stored['max_upload_body_size_mb'] ?? 32);
            $this->cache_lifetime_static = (string) ($stored['cache_lifetime_static'] ?? 'none');
            $this->default_index = (array) ($stored['default_index'] ?? ['index.php', 'index.html']);
        } else {
            $this->force_https = (bool) $this->domain->force_https;
        }

        $this->loadGeneratedConfig(app(AgentClient::class));
    }

    public function loadGeneratedConfig(AgentClient $client): void
    {
        $server = $this->domain->account->server;
        if (! $server) {
            return;
        }

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.custom.read',
                payload: ['domain' => $this->domain->domain],
                dryRun: false
            );

            if ($res['success']) {
                $this->generatedCustomConfig = $res['data']['content'] ?? '';
                $this->customConfigExists = (bool) ($res['data']['exists'] ?? false);
            }
        } catch (\Throwable) {
            $this->generatedCustomConfig = '';
        }
    }

    public function addRedirect(): void
    {
        if (count($this->redirects) >= 20) {
            $this->addError('redirects', 'Maximum 20 custom redirects allowed.');
            return;
        }

        $this->redirects[] = [
            'source' => '/',
            'target' => 'https://',
            'code' => 301,
        ];
    }

    public function removeRedirect(int $index): void
    {
        if (isset($this->redirects[$index])) {
            unset($this->redirects[$index]);
            $this->redirects = array_values($this->redirects);
        }
    }

    public function saveSettings(AgentClient $client): void
    {
        $this->authorize('update', $this->domain);
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->nginxTestOutput = null;

        // Validation against forbidden characters (; { } $ ' " ` newlines)
        $noInjectionRegex = '/^[^;{}$\'"`\r\n]+$/';

        $this->validate([
            'redirects' => ['array', 'max:20'],
            'redirects.*.source' => ['required', 'string', 'starts_with:/', "regex:{$noInjectionRegex}"],
            'redirects.*.target' => ['required', 'string', "regex:{$noInjectionRegex}"],
            'redirects.*.code' => ['required', Rule::in([301, 302])],
            'custom_error_404' => ['nullable', 'string', 'starts_with:/', "regex:{$noInjectionRegex}"],
            'custom_error_403' => ['nullable', 'string', 'starts_with:/', "regex:{$noInjectionRegex}"],
            'clean_urls' => ['boolean'],
            'force_https' => ['boolean'],
            'max_upload_body_size_mb' => ['required', 'integer', 'between:1,128'],
            'cache_lifetime_static' => ['required', Rule::in(['none', '1d', '7d', '30d'])],
            'default_index' => ['required', 'array'],
            'default_index.*' => [Rule::in(['index.php', 'index.html', 'index.htm'])],
        ]);

        // Validate error pages are not directory traversals
        if (! empty($this->custom_error_404) && str_contains($this->custom_error_404, '..')) {
            $this->addError('custom_error_404', 'Path traversal (..) is not permitted.');
            return;
        }
        if (! empty($this->custom_error_403) && str_contains($this->custom_error_403, '..')) {
            $this->addError('custom_error_403', 'Path traversal (..) is not permitted.');
            return;
        }

        // Validate redirect loops
        $bareDomain = strtolower($this->domain->domain);
        foreach ($this->redirects as $i => $r) {
            if (str_contains($r['source'], '..')) {
                $this->addError("redirects.{$i}.source", 'Path traversal (..) is not permitted.');
                return;
            }

            $sourceTrim = rtrim(strtolower($r['source']), '/');
            $targetTrim = rtrim(strtolower($r['target']), '/');

            if ($targetTrim === $sourceTrim ||
                $targetTrim === "https://{$bareDomain}{$sourceTrim}" ||
                $targetTrim === "http://{$bareDomain}{$sourceTrim}" ||
                $targetTrim === "https://www.{$bareDomain}{$sourceTrim}" ||
                $targetTrim === "http://www.{$bareDomain}{$sourceTrim}") {
                $this->addError("redirects.{$i}.target", 'Redirect loop detected: target cannot point to the source URL.');
                return;
            }
        }

        $server = $this->domain->account->server;
        if (! $server) {
            $this->errorMessage = 'No server associated with this domain account.';
            return;
        }

        $cleanSettings = [
            'redirects' => array_values($this->redirects),
            'custom_error_404' => $this->custom_error_404,
            'custom_error_403' => $this->custom_error_403,
            'clean_urls' => $this->clean_urls,
            'force_https' => $this->force_https,
            'max_upload_body_size_mb' => (int) $this->max_upload_body_size_mb,
            'cache_lifetime_static' => $this->cache_lifetime_static,
            'default_index' => array_values($this->default_index),
        ];

        try {
            $res = $client->execute(
                server: $server,
                action: 'nginx.custom.write',
                payload: [
                    'domain' => $this->domain->domain,
                    'settings' => $cleanSettings,
                ],
                dryRun: false
            );

            if ($res['success']) {
                $this->domain->update([
                    'web_server_settings' => $cleanSettings,
                ]);

                // Create AuditLog row without storing rendered config content
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'nginx.custom.write',
                    'target_type' => Domain::class,
                    'target_id' => (string) $this->domain->id,
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'new_values' => [
                        'domain' => $this->domain->domain,
                        'clean_urls' => $this->clean_urls,
                        'force_https' => $this->force_https,
                        'max_upload_body_size_mb' => $this->max_upload_body_size_mb,
                        'cache_lifetime_static' => $this->cache_lifetime_static,
                        'redirects_count' => count($this->redirects),
                    ],
                ]);

                $this->statusMessage = 'Web server settings applied and Nginx reloaded successfully.';
                $this->loadGeneratedConfig($client);
            } else {
                $this->errorMessage = 'Nginx syntax validation failed. Your settings were not applied.';
                $this->nginxTestOutput = $res['data']['nginx_output'] ?? ($res['error'] ?? 'Syntax error');
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Error communicating with server agent: ' . $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.domains.web-server-settings')->layout('components.layouts.app', [
            'title' => 'Web Server Settings &middot; ' . $this->domain->domain . ' &middot; ' . config('app.name', 'HPanel'),
        ]);
    }
}
