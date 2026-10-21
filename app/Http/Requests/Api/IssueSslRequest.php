<?php

namespace App\Http\Requests\Api;

use App\Models\Account;

class IssueSslRequest extends ApiRequest
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
            'domain' => [
                'required',
                'string',
                'regex:'.Account::DOMAIN_REGEX,
            ],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ];
    }
}
