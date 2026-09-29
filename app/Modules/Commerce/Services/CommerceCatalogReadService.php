<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CommerceCatalogReadService
{
    /** @return array<string, mixed> */
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
                ->whereHas('commerceProductVariant', static function (Builder $query): void {
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

        return [
            'product_count' => CommerceProduct::query()->count(),
            'active_product_count' => CommerceProduct::query()
                ->where('status', CommerceProduct::STATUS_ACTIVE)
                ->count(),
            'variant_count' => CommerceProductVariant::query()->count(),
            'active_variant_count' => $activeVariantCount,
            'provider_keys' => $this->providerKeys(),
            'role_bindings' => $roleBindings,
            'mapping_coverage' => $coverage,
        ];
    }

    /** @param array<string, mixed> $input */
    public function filters(array $input): array
    {
        $search = trim((string) ($input['q'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $mapping = trim((string) ($input['mapping'] ?? ''));

        return [
            'q' => $search !== '' ? Str::limit($search, 120, '') : null,
            'status' => in_array($status, [
                CommerceProduct::STATUS_ACTIVE,
                CommerceProduct::STATUS_DRAFT,
                CommerceProduct::STATUS_ARCHIVED,
            ], true) ? $status : null,
            'provider' => $provider !== '' ? Str::limit($provider, 120, '') : null,
            'mapping' => in_array($mapping, ['inventory_mapped', 'inventory_gap'], true)
                ? $mapping
                : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function products(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $filters = $this->filters($filters);
        $inventoryProviderKey = $this->roleBindings()[CommerceProviderRole::Inventory->value]['default'] ?? null;

        $query = CommerceProduct::query()
            ->with([
                'providerMappings' => static fn ($query) => $query
                    ->orderBy('provider_key')
                    ->orderBy('reference_type'),
            ])
            ->withCount('variants');

        if ($filters['q'] !== null) {
            $search = $filters['q'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('vendor', 'like', "%{$search}%")
                    ->orWhere('product_type', 'like', "%{$search}%")
                    ->orWhereHas('variants', static fn (Builder $variantQuery) => $variantQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"));
            });
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['provider'] !== null) {
            $provider = $filters['provider'];
            $query->where(function (Builder $providerQuery) use ($provider): void {
                $providerQuery
                    ->whereHas('providerMappings', static fn (Builder $mappingQuery) => $mappingQuery
                        ->where('provider_key', $provider))
                    ->orWhereHas('variants.providerMappings', static fn (Builder $mappingQuery) => $mappingQuery
                        ->where('provider_key', $provider));
            });
        }

        if (is_string($inventoryProviderKey) && $inventoryProviderKey !== '') {
            if ($filters['mapping'] === 'inventory_mapped') {
                $query->whereHas('variants', static fn (Builder $variantQuery) => $variantQuery
                    ->where('status', CommerceProductVariant::STATUS_ACTIVE)
                    ->whereHas('providerMappings', static fn (Builder $mappingQuery) => $mappingQuery
                        ->where('provider_key', $inventoryProviderKey)
                        ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)));
            } elseif ($filters['mapping'] === 'inventory_gap') {
                $query->whereHas('variants', static fn (Builder $variantQuery) => $variantQuery
                    ->where('status', CommerceProductVariant::STATUS_ACTIVE)
                    ->whereDoesntHave('providerMappings', static fn (Builder $mappingQuery) => $mappingQuery
                        ->where('provider_key', $inventoryProviderKey)
                        ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)));
            }
        }

        return $query
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [CommerceProduct::STATUS_ACTIVE, CommerceProduct::STATUS_DRAFT],
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(max(1, min(100, $perPage)))
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    public function filterOptions(): array
    {
        return [
            'providers' => $this->providerKeys(),
            'statuses' => [
                CommerceProduct::STATUS_ACTIVE,
                CommerceProduct::STATUS_DRAFT,
                CommerceProduct::STATUS_ARCHIVED,
            ],
            'inventory_provider_key' => $this->roleBindings()[CommerceProviderRole::Inventory->value]['default'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
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
        $roleBindings = $this->roleBindings();

        return [
            'product' => $product,
            'variants' => $product->variants,
            'latest_inventory_effects' => $this->latestEffects($variantIds),
            'role_bindings' => $roleBindings,
            'inventory_provider_key' => $roleBindings[CommerceProviderRole::Inventory->value]['default'] ?? null,
        ];
    }

    /** @param Collection<int, int> $variantIds @return array<int, CommerceInventoryEffect> */
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

    /** @return array<int, string> */
    private function providerKeys(): array
    {
        return CommerceProductProviderMapping::query()
            ->select('provider_key')
            ->distinct()
            ->pluck('provider_key')
            ->merge(
                CommerceProductVariantProviderMapping::query()
                    ->select('provider_key')
                    ->distinct()
                    ->pluck('provider_key'),
            )
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, array{label:string, default:?string, scopes:array<string, string>}> */
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