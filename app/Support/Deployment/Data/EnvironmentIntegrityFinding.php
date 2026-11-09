<?php

namespace App\Support\Deployment\Data;

/**
 * Redacted, read-only evidence of a deployment environment contract violation.
 * The key and catalog metadata are safe to report; environment values are not.
 */
final readonly class EnvironmentIntegrityFinding
{
    public const UNREGISTERED_REQUIREMENT = 'unregistered_requirement';
    public const UNREGISTERED_SETUP_KEY = 'unregistered_setup_key';
    public const WRONG_SCOPE = 'wrong_environment_scope';
    public const DUPLICATE_KEY = 'duplicate_environment_key';
    public const MALFORMED_FILE = 'malformed_environment_file';
    public const UNREADABLE_FILE = 'unreadable_environment_file';

    public function __construct(
        public string $code,
        public string $key,
        public string $owner,
        public ?string $scope = null,
        public ?string $expectedScope = null,
        public ?string $setupStep = null,
        public ?int $line = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'key' => $this->key,
            'owner' => $this->owner,
            'scope' => $this->scope,
            'expected_scope' => $this->expectedScope,
            'setup_step' => $this->setupStep,
            'line' => $this->line,
        ];
    }
}