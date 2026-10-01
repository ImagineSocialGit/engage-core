<?php

namespace App\Support\Clients;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Support\ServiceProvider;

final readonly class ClientPackageManifest
{
    /**
     * @param list<class-string<ServiceProvider>> $providers
     * @param array<string, EnvironmentVariableDefinition> $environmentDefinitions
     * @param array<string, array<string, mixed>> $moduleDefinitions
     * @param array<string, array{path:string}> $migrationScopes
     */
    public function __construct(
        private array $providers = [],
        private array $environmentDefinitions = [],
        private array $moduleDefinitions = [],
        private array $migrationScopes = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, EnvironmentVariableDefinition>
     */
    public function environmentDefinitions(): array
    {
        return $this->environmentDefinitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function moduleDefinitions(): array
    {
        return $this->moduleDefinitions;
    }

    /**
     * @return array<string, array{path:string}>
     */
    public function migrationScopes(): array
    {
        return $this->migrationScopes;
    }
}