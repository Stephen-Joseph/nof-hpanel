<?php

namespace App\Http\Requests\Api;

use App\Models\Account;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
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
            'domain' => [
                'required',
                'string',
                'regex:'.Account::DOMAIN_REGEX,
                'unique:accounts,primary_domain',
            ],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'customer_email' => ['required', 'email'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
        ];
    }
}
