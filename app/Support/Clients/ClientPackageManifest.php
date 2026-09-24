<?php

namespace App\Support\Clients;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Support\ServiceProvider;

final readonly class ClientPackageManifest
{
    /**
     * @param list<class-string<ServiceProvider>> $providers
     * @param array<string, EnvironmentVariableDefinition> $environmentDefinitions
     */
    public function __construct(
        private array $providers = [],
        private array $environmentDefinitions = [],
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
}