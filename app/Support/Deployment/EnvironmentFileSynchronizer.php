<?php

namespace App\Support\Deployment;

use App\Support\Deployment\Data\DeploymentPlan;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use Illuminate\Support\Env;
use RuntimeException;

final class EnvironmentFileSynchronizer
{
    public function __construct(
        private readonly EnvironmentFileRepository $environmentFiles,
    ) {}

    /**
     * Add only missing required variable names. Existing keys and values are
     * never changed, and unused keys are never removed automatically.
     *
     * @return array<int, array{key:string,path:string}>
     */
    public function writeMissingRequiredKeys(DeploymentPlan $plan): array
    {
        $byPath = [];

        foreach ($plan->environmentRequirements as $resolved) {
            if (! $resolved instanceof ResolvedEnvironmentRequirement
                || ! $resolved->requirement->isRequired()
            ) {
                continue;
            }

            $definition = $resolved->definition;
            $path = $this->environmentFiles->pathForScope($definition->scope);

            if ($this->environmentFiles->contains($definition->scope, $definition->key)) {
                continue;
            }

            $byPath[$path][] = $resolved;
        }

        $written = [];

        foreach ($byPath as $path => $requirements) {
            usort(
                $requirements,
                static fn (
                    ResolvedEnvironmentRequirement $left,
                    ResolvedEnvironmentRequirement $right,
                ): int => $left->definition->key <=> $right->definition->key,
            );

            $this->appendRequirements($path, $requirements, $plan);

            foreach ($requirements as $resolved) {
                $written[] = [
                    'key' => $resolved->definition->key,
                    'path' => $this->displayPath($path),
                ];
            }
        }

        return $written;
    }

    /**
     * @param array<int, ResolvedEnvironmentRequirement> $requirements
     */
    private function appendRequirements(
        string $path,
        array $requirements,
        DeploymentPlan $plan,
    ): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException(
                "Environment directory does not exist [{$directory}].",
            );
        }

        $existing = is_file($path)
            ? (string) file_get_contents($path)
            : '';

        $prefix = $existing === ''
            ? "# Engage Core runtime environment\n"
            : rtrim($existing)."\n\n";

        $lines = [
            '# Required by the current committed Engage deployment plan.',
            '# Populate blank values before continuing deployment.',
        ];

        foreach ($requirements as $resolved) {
            $definition = $resolved->definition;
            $key = $definition->key;
            $effective = Env::get($key);
            $value = '';

            if (! $definition->secret && $resolved->requirement->expectedValue !== null) {
                $value = $this->serializeValue($resolved->requirement->expectedValue);
            } elseif (! $definition->secret && $this->hasUsableValue($effective)) {
                $value = $this->serializeValue($effective);
            } elseif ($key === 'CLIENT_KEY' && $plan->clientKey !== '') {
                $value = $plan->clientKey;
            }

            $lines[] = $key.'='.$value;
        }

        $content = $prefix.implode("\n", $lines)."\n";
        $temporary = tempnam($directory, '.engage-env.');

        if ($temporary === false) {
            throw new RuntimeException(
                "Unable to create temporary environment file in [{$directory}].",
            );
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException(
                    "Unable to write temporary environment file [{$temporary}].",
                );
            }

            $mode = is_file($path)
                ? (fileperms($path) & 0777)
                : 0640;
            $group = is_file($path)
                ? filegroup($path)
                : filegroup($directory);
            $existingOwner = is_file($path) ? fileowner($path) : null;
            $temporaryOwner = fileowner($temporary);

            if (is_int($existingOwner)
                && is_int($temporaryOwner)
                && $existingOwner !== $temporaryOwner
            ) {
                throw new RuntimeException(sprintf(
                    'Refusing to replace environment file [%s] as a different filesystem owner. Run the command as the environment-file owner.',
                    $path,
                ));
            }

            chmod($temporary, $mode ?: 0640);

            if (is_int($group) && ! @chgrp($temporary, $group)) {
                throw new RuntimeException(
                    "Unable to preserve environment-file group for [{$path}].",
                );
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException(
                    "Unable to atomically replace environment file [{$path}].",
                );
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function hasUsableValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }

    private function serializeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '' || preg_match('/[\s#="\\\\]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        ).'"';
    }

    private function displayPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }
}