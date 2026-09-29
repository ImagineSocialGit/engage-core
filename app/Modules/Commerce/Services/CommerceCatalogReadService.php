<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CommerceCatalogReadService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $roleBindings = $this->roleBindings();
        $activeVariantCount = CommerceProductVariant::query()
            ->where('status', CommerceProductVariant::STATUS_ACTIVE)
            ->count();

        $coverage = [];

        foreach ([CommerceProviderRole::Catalog, CommerceProviderRole::Inventory] as $role) {
            $providerKey = $roleBindings[$role->value]['default'] ?? null;

            if (! is_string($providerKey) || $providerKey === '') {
                continue;
            }

            $mappedVariantCount = CommerceProductVariantProviderMapping::query()
                ->where('provider_key', $providerKey)
                ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
                ->whereHas('commerceProductVariant', static function ($query): void {
                    $query->where('status', CommerceProductVariant::STATUS_ACTIVE);
                })
                ->distinct('commerce_product_variant_id')
                ->count('commerce_product_variant_id');

            $coverage[$role->value] = [
                'provider_key' => $providerKey,
                'mapped_variant_count' => $mappedVariantCount,
                'active_variant_count' => $activeVariantCount,
                'unmapped_variant_count' => max(0, $activeVariantCount - $mappedVariantCount),
            ];
        }

        $providerKeys = CommerceProductVariantProviderMapping::query()
            ->select('provider_key')
            ->distinct()
            ->orderBy('provider_key')
            ->pluck('provider_key')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->values()
            ->all();

        return [
            'product_count' => CommerceProduct::query()->count(),
            'active_product_count' => CommerceProduct::query()
                ->where('status', CommerceProduct::STATUS_ACTIVE)
                ->count(),
            'variant_count' => CommerceProductVariant::query()->count(),
            'active_variant_count' => $activeVariantCount,
            'provider_keys' => $providerKeys,
            'role_bindings' => $roleBindings,
            'mapping_coverage' => $coverage,
        ];
    }

    public function products(int $perPage = 30): LengthAwarePaginator
    {
        return CommerceProduct::query()
            ->with([
                'providerMappings' => static fn ($query) => $query
                    ->orderBy('provider_key')
                    ->orderBy('reference_type'),
            ])
            ->withCount('variants')
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [CommerceProduct::STATUS_ACTIVE, CommerceProduct::STATUS_DRAFT],
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * @return array<string, mixed>
     */
    public function productDetail(CommerceProduct $product): array
    {
        $product->load([
            'providerMappings' => static fn ($query) => $query
                ->orderBy('provider_key')
                ->orderBy('reference_type'),
            'variants' => static fn ($query) => $query
                ->with([
                    'providerMappings' => static fn ($mappingQuery) => $mappingQuery
                        ->orderBy('provider_key')
                        ->orderBy('reference_type'),
                ])
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $variantIds = $product->variants
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        $latestEffects = $this->latestEffects($variantIds);

        return [
            'product' => $product,
            'variants' => $product->variants,
            'latest_inventory_effects' => $latestEffects,
            'role_bindings' => $this->roleBindings(),
        ];
    }

    /**
     * @param Collection<int, int> $variantIds
     * @return array<int, CommerceInventoryEffect>
     */
    private function latestEffects(Collection $variantIds): array
    {
        if ($variantIds->isEmpty()) {
            return [];
        }

        $latestIds = CommerceInventoryEffect::query()
            ->whereIn('commerce_product_variant_id', $variantIds->all())
            ->selectRaw('MAX(id) as id')
            ->groupBy('commerce_product_variant_id')
            ->pluck('id')
            ->filter()
            ->all();

        if ($latestIds === []) {
            return [];
        }

        return CommerceInventoryEffect::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy(static fn (CommerceInventoryEffect $effect): int => (int) $effect->commerce_product_variant_id)
            ->all();
    }

    /**
     * @return array<string, array{label:string, default:?string, scopes:array<string, string>}>
     */
    private function roleBindings(): array
    {
        $bindings = [];

        foreach (CommerceProviderRole::cases() as $role) {
            $configured = config('commerce.provider_roles.'.$role->value, []);
            $configured = is_array($configured) ? $configured : [];
            $default = $configured['default'] ?? null;
            $default = is_string($default) && trim($default) !== ''
                ? trim($default)
                : null;
            $scopes = [];

            foreach (is_array($configured['scopes'] ?? null) ? $configured['scopes'] : [] as $scope => $provider) {
                if (! is_string($scope)
                    || trim($scope) === ''
                    || ! is_string($provider)
                    || trim($provider) === ''
                ) {
                    continue;
                }

                $scopes[trim($scope)] = trim($provider);
            }

            ksort($scopes);

            $bindings[$role->value] = [
                'label' => Str::headline($role->value),
                'default' => $default,
                'scopes' => $scopes,
            ];
        }

        return $bindings;
    }
}