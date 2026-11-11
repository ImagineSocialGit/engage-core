<?php

namespace App\Support\Deployment\Credentials;

use Closure;
use InvalidArgumentException;

/** Secret resolution is deferred until proof; inventory never calls the consumer. */
final readonly class CoreCredentialRuntimeBinding
{
    public function __construct(
        public string $owner,
        public string $purpose,
        public string $sourcePlatform,
        public string $callerId,
        public string $sourceOrigin,
        public string $secretEnvironmentKey,
        private Closure $consumer,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/D', $owner) !== 1
            || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $purpose) !== 1
            || preg_match('/\A[a-z][a-z0-9_]*\z/D', $sourcePlatform) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $callerId) !== 1
            || preg_match('/\A[A-Z][A-Z0-9_]{0,255}\z/D', $secretEnvironmentKey) !== 1
        ) {
            throw new InvalidArgumentException('Invalid runtime credential binding.');
        }
    }

    public function resolveSecret(): ?string
    {
        $value = ($this->consumer)();

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return [
            'owner' => $this->owner,
            'purpose' => $this->purpose,
            'source_platform' => $this->sourcePlatform,
            'caller_id' => $this->callerId,
            'source_origin' => $this->sourceOrigin,
            'secret_environment_key' => $this->secretEnvironmentKey,
        ];
    }
}