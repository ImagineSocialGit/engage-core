<?php

namespace App\Support\Deployment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Dotenv\Dotenv;
use RuntimeException;

class EnvironmentFileRepository
{
    public function pathForScope(string $scope): string
    {
        return match ($scope) {
            EnvironmentVariableDefinition::SCOPE_ROOT => base_path('.env'),
            EnvironmentVariableDefinition::SCOPE_CLIENT => $this->clientEnvironmentPath(),
            default => throw new RuntimeException("Unsupported environment scope [{$scope}]."),
        };
    }

    /** @return array<string, string> */
    public function valuesForScope(string $scope): array
    {
        $path = $this->pathForScope($scope);

        if (! is_file($path)) {
            return [];
        }

        $directory = dirname($path);
        $filename = basename($path);

        return Dotenv::createArrayBacked($directory, $filename)->safeLoad();
    }

    public function contains(string $scope, string $key): bool
    {
        return array_key_exists($key, $this->valuesForScope($scope));
    }

    private function clientEnvironmentPath(): string
    {
        $path = config('client.env_path');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Unable to resolve selected-client environment path.');
        }

        return $path;
    }
}