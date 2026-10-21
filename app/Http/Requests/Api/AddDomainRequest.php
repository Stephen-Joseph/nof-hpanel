<?php

namespace App\Http\Requests\Api;

use App\Models\Account;

class AddDomainRequest extends ApiRequest
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
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'domain' => [
                'required',
                'string',
                'regex:'.Account::DOMAIN_REGEX,
            ],
            'document_root' => ['nullable', 'string', 'max:255'],
        ];
    }
}
