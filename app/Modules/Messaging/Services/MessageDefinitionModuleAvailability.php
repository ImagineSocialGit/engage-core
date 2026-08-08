<?php

namespace App\Modules\Messaging\Services;

use App\Support\Modules\ModuleManager;

final class MessageDefinitionModuleAvailability
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
    ) {}

    public function standardDefinitionsAvailable(string $scope): bool
    {
        return $this->moduleAvailable(
            $this->standardOwnerModule($scope),
        );
    }

    public function campaignDefinitionsAvailable(): bool
    {
        return $this->moduleAvailable('campaigns');
    }

    public function scopeContainsAvailableDefinitions(
        string $scope,
        mixed $scopeConfig,
    ): bool {
        if ($this->standardDefinitionsAvailable($scope)) {
            return true;
        }

        return $this->campaignDefinitionsAvailable()
            && $this->containsCampaignDefinitions($scopeConfig);
    }

    public function assignmentAvailable(
        string $scope,
        ?string $campaignKey = null,
        ?int $campaignStep = null,
    ): bool {
        if ($this->filled($campaignKey) || $campaignStep !== null) {
            return $this->campaignDefinitionsAvailable();
        }

        return $this->standardDefinitionsAvailable($scope);
    }

    public function catalogDefinitionAvailable(
        ?string $moduleKey,
        string $scope,
    ): bool {
        $moduleKey = $this->normalizeNullable($moduleKey);

        if ($moduleKey !== null) {
            return $this->moduleAvailable($moduleKey);
        }

        return $this->standardDefinitionsAvailable($scope);
    }

    public function standardOwnerModule(string $scope): string
    {
        $scope = $this->normalize($scope);

        return str_starts_with($scope, 'webinar')
            ? 'webinars'
            : 'messaging';
    }

    public function moduleAvailable(string $moduleKey): bool
    {
        return in_array(
            $this->normalize($moduleKey),
            $this->moduleManager->enabledKeysWithDependencies(),
            true,
        );
    }

    private function containsCampaignDefinitions(mixed $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        if (array_key_exists('campaigns', $config)) {
            return true;
        }

        foreach ($config as $value) {
            if (is_array($value) && $this->containsCampaignDefinitions($value)) {
                return true;
            }
        }

        return false;
    }

    private function filled(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizeNullable(?string $value): ?string
    {
        if (! $this->filled($value)) {
            return null;
        }

        return $this->normalize((string) $value);
    }

    private function normalize(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}