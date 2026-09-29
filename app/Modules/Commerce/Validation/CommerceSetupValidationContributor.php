<?php

namespace App\Modules\Commerce\Validation;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryReadProvider;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CommerceSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'commerce.provider_roles';
    private const MODULE = 'commerce';

    public function __construct(
        private readonly CommerceProviderRegistry $providers,
        private readonly ConfigRepository $config,
    ) {}

    public function findings(): iterable
    {
        $bindings = $this->config->get('commerce.provider_roles', []);

        if (! is_array($bindings)) {
            yield $this->error(
                code: 'commerce.provider_roles.invalid',
                message: 'commerce.provider_roles must be an array.',
                path: 'commerce.provider_roles',
            );

            return;
        }

        try {
            $providers = $this->providers->all();
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'commerce.providers.registry_invalid',
                message: $exception->getMessage(),
                path: 'commerce.providers',
                context: ['exception' => $exception::class],
            );

            return;
        }

        $knownRoles = [];

        foreach (CommerceProviderRole::cases() as $role) {
            $knownRoles[$role->value] = $role;
        }

        foreach ($bindings as $roleKey => $binding) {
            if (! is_string($roleKey) || ! isset($knownRoles[$roleKey])) {
                yield $this->error(
                    code: 'commerce.provider_roles.unknown_role',
                    message: 'Commerce provider role configuration contains an unknown role key.',
                    path: 'commerce.provider_roles.'.(is_scalar($roleKey) ? (string) $roleKey : 'unknown'),
                    context: ['role_key' => is_scalar($roleKey) ? (string) $roleKey : get_debug_type($roleKey)],
                );
            }
        }

        foreach ($knownRoles as $roleKey => $role) {
            if (! array_key_exists($roleKey, $bindings)) {
                continue;
            }

            yield from $this->roleFindings(
                role: $role,
                binding: $bindings[$roleKey],
                providers: $providers,
            );
        }

        yield from $this->mappingFindings($providers);
        yield from $this->inventoryRouteFindings($bindings, $providers);
    }

    /**
     * @param array<string, object> $providers
     * @return iterable<int, SetupValidationFinding>
     */
    private function roleFindings(
        CommerceProviderRole $role,
        mixed $binding,
        array $providers,
    ): iterable {
        $basePath = 'commerce.provider_roles.'.$role->value;

        if (! is_array($binding)) {
            yield $this->error(
                code: 'commerce.provider_roles.binding_invalid',
                message: "Commerce provider role [{$role->value}] must use an array binding.",
                path: $basePath,
                context: ['role' => $role->value],
            );

            return;
        }

        foreach (array_keys($binding) as $key) {
            if (is_string($key) && in_array($key, ['default', 'scopes'], true)) {
                continue;
            }

            yield $this->error(
                code: 'commerce.provider_roles.binding_key_unknown',
                message: "Commerce provider role [{$role->value}] contains an unsupported binding key.",
                path: $basePath,
                context: [
                    'role' => $role->value,
                    'binding_key' => is_scalar($key) ? (string) $key : get_debug_type($key),
                ],
            );
        }

        $resolved = [];

        if (array_key_exists('default', $binding)) {
            $default = $binding['default'];
            $providerKey = $this->providerKey($default);

            if ($providerKey !== null) {
                $resolved[$providerKey] = $basePath.'.default';
            } elseif (! $this->unconfiguredProviderValue($default)) {
                yield $this->error(
                    code: 'commerce.provider_roles.provider_key_invalid',
                    message: "Commerce provider role [{$role->value}] default provider must be a string or null.",
                    path: $basePath.'.default',
                    context: ['role' => $role->value],
                );
            }
        }

        $scopes = $binding['scopes'] ?? [];

        if (! is_array($scopes)) {
            yield $this->error(
                code: 'commerce.provider_roles.scopes_invalid',
                message: "Commerce provider role [{$role->value}] scopes must be an array.",
                path: $basePath.'.scopes',
                context: ['role' => $role->value],
            );

            $scopes = [];
        }

        foreach ($scopes as $scope => $value) {
            if (! is_string($scope) || trim($scope) === '') {
                yield $this->error(
                    code: 'commerce.provider_roles.scope_key_invalid',
                    message: "Commerce provider role [{$role->value}] contains an invalid scope key.",
                    path: $basePath.'.scopes',
                    context: ['role' => $role->value],
                );

                continue;
            }

            $providerKey = $this->providerKey($value);

            if ($providerKey === null) {
                yield $this->error(
                    code: 'commerce.provider_roles.provider_key_invalid',
                    message: "Commerce provider role [{$role->value}] scope [{$scope}] must resolve to a non-empty provider key.",
                    path: $basePath.'.scopes.'.$scope,
                    context: [
                        'role' => $role->value,
                        'scope' => $scope,
                    ],
                );

                continue;
            }

            $resolved[$providerKey] = $basePath.'.scopes.'.$scope;
        }

        foreach ($resolved as $providerKey => $path) {
            $provider = $providers[$providerKey] ?? null;

            if (! is_object($provider)) {
                yield $this->error(
                    code: 'commerce.provider_roles.provider_unregistered',
                    message: "Commerce provider role [{$role->value}] references unregistered provider [{$providerKey}].",
                    path: $path,
                    context: [
                        'role' => $role->value,
                        'provider_key' => $providerKey,
                    ],
                );

                continue;
            }

            $contract = $role->contract();

            if (! $provider instanceof $contract) {
                yield $this->error(
                    code: 'commerce.provider_roles.contract_mismatch',
                    message: "Commerce provider [{$providerKey}] does not implement the required [{$role->value}] capability.",
                    path: $path,
                    context: [
                        'role' => $role->value,
                        'provider_key' => $providerKey,
                        'required_contract' => $contract,
                    ],
                );

                continue;
            }

            if ($role !== CommerceProviderRole::Inventory) {
                continue;
            }

            if (! $provider instanceof CommerceInventoryReadProvider) {
                yield $this->error(
                    code: 'commerce.inventory.read_capability_missing',
                    message: "Commerce inventory provider [{$providerKey}] cannot perform authoritative inventory reads.",
                    path: $path,
                    context: ['provider_key' => $providerKey],
                );
            }

            if (! $provider instanceof CommerceInventoryAdjustmentProvider) {
                yield $this->error(
                    code: 'commerce.inventory.adjustment_capability_missing',
                    message: "Commerce inventory provider [{$providerKey}] cannot perform required outbound inventory adjustments.",
                    path: $path,
                    context: ['provider_key' => $providerKey],
                );
            }
        }
    }

    /**
     * @param array<string, object> $providers
     * @return iterable<int, SetupValidationFinding>
     */
    private function mappingFindings(array $providers): iterable
    {
        $productMappingsTable = (new CommerceProductProviderMapping())->getTable();
        $variantMappingsTable = (new CommerceProductVariantProviderMapping())->getTable();

        if (Schema::hasTable($productMappingsTable)) {
            foreach (
                CommerceProductProviderMapping::query()
                    ->where('status', CommerceProductProviderMapping::STATUS_ACTIVE)
                    ->with('commerceProduct')
                    ->get() as $mapping
            ) {
                if (! array_key_exists((string) $mapping->provider_key, $providers)) {
                    yield $this->warning(
                        code: 'commerce.mappings.provider_unregistered',
                        message: 'An active Commerce product mapping references a provider that is not currently registered.',
                        path: $productMappingsTable.'.'.$mapping->getKey(),
                        context: [
                            'mapping_type' => 'product',
                            'mapping_id' => (int) $mapping->getKey(),
                            'provider_key' => (string) $mapping->provider_key,
                        ],
                    );
                }
    
                if ($mapping->commerceProduct === null) {
                    yield $this->warning(
                        code: 'commerce.mappings.canonical_record_unavailable',
                        message: 'An active Commerce product mapping points to a canonical product that is no longer available.',
                        path: $productMappingsTable.'.'.$mapping->getKey(),
                        context: [
                            'mapping_type' => 'product',
                            'mapping_id' => (int) $mapping->getKey(),
                        ],
                    );
                }
            }
        }

        if (Schema::hasTable($variantMappingsTable)) {
            foreach (
                CommerceProductVariantProviderMapping::query()
                    ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
                    ->with('commerceProductVariant')
                    ->get() as $mapping
            ) {
                if (! array_key_exists((string) $mapping->provider_key, $providers)) {
                    yield $this->warning(
                        code: 'commerce.mappings.provider_unregistered',
                        message: 'An active Commerce variant mapping references a provider that is not currently registered.',
                        path: $variantMappingsTable.'.'.$mapping->getKey(),
                        context: [
                            'mapping_type' => 'variant',
                            'mapping_id' => (int) $mapping->getKey(),
                            'provider_key' => (string) $mapping->provider_key,
                        ],
                    );
                }
    
                if ($mapping->commerceProductVariant === null) {
                    yield $this->warning(
                        code: 'commerce.mappings.canonical_record_unavailable',
                        message: 'An active Commerce variant mapping points to a canonical variant that is no longer available.',
                        path: $variantMappingsTable.'.'.$mapping->getKey(),
                        context: [
                            'mapping_type' => 'variant',
                            'mapping_id' => (int) $mapping->getKey(),
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $bindings
     * @param array<string, object> $providers
     * @return iterable<int, SetupValidationFinding>
     */
    private function inventoryRouteFindings(array $bindings, array $providers): iterable
    {
        $effectsTable = (new CommerceInventoryEffect())->getTable();
        $variantMappingsTable = (new CommerceProductVariantProviderMapping())->getTable();

        if (! Schema::hasTable($effectsTable)
            || ! Schema::hasTable($variantMappingsTable)
        ) {
            return;
        }

        $effects = CommerceInventoryEffect::query()
            ->where('authority_mode', CommerceInventoryAuthorityMode::AdjustmentRequired->value)
            ->whereIn('status', [
                CommerceInventoryEffect::STATUS_RECORDED,
                CommerceInventoryEffect::STATUS_FAILED,
            ])
            ->orderBy('id')
            ->get();

        if ($effects->isEmpty()) {
            return;
        }

        $inventoryBinding = $bindings[CommerceProviderRole::Inventory->value] ?? null;

        foreach ($effects as $effect) {
            $providerKey = is_array($inventoryBinding)
                ? $this->providerForScope($inventoryBinding, $effect->inventory_scope)
                : null;

            if ($providerKey === null) {
                yield $this->error(
                    code: 'commerce.inventory.effect_authority_missing',
                    message: 'A Commerce inventory effect requires an outbound adjustment but no inventory authority resolves for its scope.',
                    path: $effectsTable.'.'.$effect->getKey(),
                    context: [
                        'commerce_inventory_effect_id' => (int) $effect->getKey(),
                        'inventory_scope' => $effect->inventory_scope,
                    ],
                );

                continue;
            }

            $provider = $providers[$providerKey] ?? null;

            if (! $provider instanceof CommerceInventoryAdjustmentProvider) {
                continue;
            }

            $mappingExists = CommerceProductVariantProviderMapping::query()
                ->where('commerce_product_variant_id', $effect->commerce_product_variant_id)
                ->where('provider_key', $providerKey)
                ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
                ->exists();

            if (! $mappingExists) {
                yield $this->error(
                    code: 'commerce.inventory.effect_mapping_missing',
                    message: 'A Commerce inventory effect requires an outbound adjustment but its canonical variant has no active mapping for the resolved inventory authority.',
                    path: $effectsTable.'.'.$effect->getKey(),
                    context: [
                        'commerce_inventory_effect_id' => (int) $effect->getKey(),
                        'commerce_product_variant_id' => (int) $effect->commerce_product_variant_id,
                        'provider_key' => $providerKey,
                        'inventory_scope' => $effect->inventory_scope,
                    ],
                );
            }
        }
    }

    /** @param array<string, mixed> $binding */
    private function providerForScope(array $binding, ?string $scope): ?string
    {
        $scope = is_string($scope) && trim($scope) !== ''
            ? trim($scope)
            : null;
        $scopes = is_array($binding['scopes'] ?? null)
            ? $binding['scopes']
            : [];

        if ($scope !== null) {
            $scoped = $this->providerKey($scopes[$scope] ?? null);

            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $this->providerKey($binding['default'] ?? null);
    }

    private function providerKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function unconfiguredProviderValue(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '');
    }

    /** @param array<string, mixed> $context */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }

    /** @param array<string, mixed> $context */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}