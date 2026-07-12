<?php

namespace App\Support\TokenContracts\Data;

use InvalidArgumentException;

class TokenContextDefinition
{
    /**
     * @param array<int, string> $sourceTokens
     * @param array<int, string> $channels
     * @param array<int, string> $purposes
     * @param array<int, string> $scopes
     * @param array<int, string> $surfaces
     */
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly string $description,
        public readonly array $sourceTokens,
        public readonly array $channels = [],
        public readonly array $purposes = [],
        public readonly array $scopes = [],
        public readonly array $surfaces = [],
    ) {
        if (trim($key) === '' || trim($owner) === '' || trim($description) === '') {
            throw new InvalidArgumentException('Token context identity fields must be non-empty strings.');
        }

        if ($sourceTokens === []) {
            throw new InvalidArgumentException("Token context [{$key}] must expose at least one registered source.");
        }

        foreach ($sourceTokens as $token) {
            if (! is_string($token) || trim($token) === '') {
                throw new InvalidArgumentException("Token context [{$key}] contains an invalid source token.");
            }
        }
    }
}
