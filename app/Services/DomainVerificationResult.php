<?php

namespace App\Services;

class DomainVerificationResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly bool $ownershipOk,
        public readonly bool $routingOk,
        public readonly bool $verified,
        public readonly string $message,
        public readonly ?string $error = null,
        public readonly array $details = []
    ) {}
}

