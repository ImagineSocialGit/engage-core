<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceProvider;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

final class CommerceProviderRoleResolver
{
    public function __construct(
        private readonly CommerceProviderRegistry $providers,
        private readonly ConfigRepository $config,
    ) {}

    public function resolve(
        CommerceProviderRole $role,
        ?string $scope = null,
    ): CommerceProvider {
        $providerKey = $this->providerKey($role, $scope);
        $provider = $this->providers->get($providerKey);
        $contract = $role->contract();

        if (! $provider instanceof $contract) {
            throw new RuntimeException(
                "Commerce provider [{$providerKey}] does not implement the required [{$role->value}] capability.",
            );
        }

        return $provider;
    }

    public function providerKey(
        CommerceProviderRole $role,
        ?string $scope = null,
    ): string {
        $binding = $this->config->get("commerce.provider_roles.{$role->value}", []);

        if (! is_array($binding)) {
            throw new RuntimeException(
                "Commerce provider role [{$role->value}] configuration is invalid.",
            );
        }

        $scopes = is_array($binding['scopes'] ?? null)
            ? $binding['scopes']
            : [];

        $scope = is_string($scope) && trim($scope) !== ''
            ? trim($scope)
            : null;

        $providerKey = $scope !== null
            ? $scopes[$scope] ?? null
            : null;

        if (! is_string($providerKey) || trim($providerKey) === '') {
            $providerKey = $binding['default'] ?? null;
        }

        if (! is_string($providerKey) || trim($providerKey) === '') {
            $context = $scope !== null ? " for scope [{$scope}]" : '';

            throw new RuntimeException(
                "Commerce provider role [{$role->value}] is not configured{$context}.",
            );
        }

        return trim($providerKey);
    }
}