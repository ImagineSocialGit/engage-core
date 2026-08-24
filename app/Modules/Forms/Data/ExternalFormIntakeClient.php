<?php

namespace App\Modules\Forms\Data;

final readonly class ExternalFormIntakeClient
{
    /**
     * @param  array<int, string>  $allowedForms
     * @param  array<int, string>  $domains
     */
    public function __construct(
        public string $id,
        private string $secret,
        public string $source,
        public string $provider,
        public array $allowedForms,
        public array $domains = [],
    ) {}

    public function allowsForm(string $formKey): bool
    {
        return in_array($formKey, $this->allowedForms, true);
    }

    public function verifies(string $canonicalRequest, string $signature): bool
    {
        return hash_equals(
            hash_hmac('sha256', $canonicalRequest, $this->secret),
            strtolower($signature),
        );
    }
}