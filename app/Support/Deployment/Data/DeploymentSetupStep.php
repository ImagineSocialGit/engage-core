<?php

namespace App\Support\Deployment\Data;

use InvalidArgumentException;

final readonly class DeploymentSetupStep
{
    /**
     * @param array<int, string> $instructions
     * @param array<int, string> $environmentKeys
     * @param array<int, string> $verification
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $reason,
        public array $instructions,
        public array $environmentKeys = [],
        public array $verification = [],
        public int $priority = 100,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->key) !== 1) {
            throw new InvalidArgumentException(
                "Invalid deployment setup step key [{$this->key}].",
            );
        }

        if (trim($this->title) === '') {
            throw new InvalidArgumentException(
                "Deployment setup step [{$this->key}] must include a title.",
            );
        }

        if (trim($this->reason) === '') {
            throw new InvalidArgumentException(
                "Deployment setup step [{$this->key}] must include a reason.",
            );
        }

        if ($this->instructions === []) {
            throw new InvalidArgumentException(
                "Deployment setup step [{$this->key}] must include at least one instruction.",
            );
        }

        $this->assertStringList($this->instructions, 'instruction');
        $this->assertStringList($this->verification, 'verification item');

        foreach ($this->environmentKeys as $environmentKey) {
            if (! is_string($environmentKey)
                || preg_match('/^[A-Z][A-Z0-9_]*$/', $environmentKey) !== 1
            ) {
                throw new InvalidArgumentException(
                    "Deployment setup step [{$this->key}] contains an invalid environment key.",
                );
            }
        }

        if (count(array_unique($this->environmentKeys)) !== count($this->environmentKeys)) {
            throw new InvalidArgumentException(
                "Deployment setup step [{$this->key}] environment keys must be unique.",
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'reason' => $this->reason,
            'instructions' => $this->instructions,
            'environment_keys' => $this->environmentKeys,
            'verification' => $this->verification,
            'priority' => $this->priority,
        ];
    }

    /** @param array<int, string> $values */
    private function assertStringList(array $values, string $label): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(
                    "Deployment setup step [{$this->key}] contains an invalid {$label}.",
                );
            }
        }
    }
}