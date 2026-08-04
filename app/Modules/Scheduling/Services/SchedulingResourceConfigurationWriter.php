<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

class SchedulingResourceConfigurationWriter
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function createResource(array $attributes): SchedulingResource
    {
        return DB::transaction(function () use ($attributes): SchedulingResource {
            return SchedulingResource::query()->create([
                'key' => $this->requiredKey($attributes['key'] ?? null),
                'name' => $this->requiredString($attributes['name'] ?? null, 'resource name'),
                'status' => $this->resourceStatus($attributes['status'] ?? null),
                'source' => SchedulingResource::SOURCE_MANUAL,
                'sort_order' => $this->nonNegativeInteger(
                    $attributes['sort_order'] ?? 0,
                    'resource sort order',
                ),
                'meta' => null,
            ])->refresh();
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateResource(
        SchedulingResource $resource,
        array $attributes,
        string $expectedUpdatedAt,
    ): SchedulingResource {
        return DB::transaction(function () use (
            $resource,
            $attributes,
            $expectedUpdatedAt,
        ): SchedulingResource {
            $locked = SchedulingResource::withTrashed()
                ->whereKey($resource->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertResourceEditable($locked);
            $this->assertFresh($locked, $expectedUpdatedAt, 'Scheduling resource');

            if (array_key_exists('key', $attributes)
                && $this->requiredKey($attributes['key']) !== $locked->key
            ) {
                throw new LogicException(
                    'Scheduling resource keys are immutable after creation.',
                );
            }

            $status = $this->resourceStatus($attributes['status'] ?? null);

            if ($status === SchedulingResource::STATUS_ARCHIVED) {
                $this->assertResourceHasNoActiveAssociations($locked);
            }

            $locked->forceFill([
                'name' => $this->requiredString(
                    $attributes['name'] ?? null,
                    'resource name',
                ),
                'status' => $status,
                'sort_order' => $this->nonNegativeInteger(
                    $attributes['sort_order'] ?? 0,
                    'resource sort order',
                ),
            ]);
            $this->saveWithVersionBump($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     */
    public function syncHostResources(
        SchedulingHost $host,
        array $resources,
        string $expectedUpdatedAt,
    ): SchedulingHost {
        return DB::transaction(function () use (
            $host,
            $resources,
            $expectedUpdatedAt,
        ): SchedulingHost {
            $lockedHost = SchedulingHost::withTrashed()
                ->whereKey($host->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedHost->trashed()) {
                throw new DomainException(
                    'Archived scheduling hosts cannot receive resource configuration changes.',
                );
            }

            $this->assertFresh($lockedHost, $expectedUpdatedAt, 'Scheduling host');
            $submitted = $this->normalizedHostResources($resources);
            $resourceModels = $this->lockedResources(array_keys($submitted));
            $existing = SchedulingHostResource::query()
                ->where('scheduling_host_id', $lockedHost->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SchedulingHostResource $row): int =>
                    (int) $row->scheduling_resource_id
                );

            foreach ($existing as $resourceId => $row) {
                if ($row->source === SchedulingHostResource::SOURCE_MANUAL
                    && ! array_key_exists((int) $resourceId, $submitted)
                    && $row->is_active
                ) {
                    $row->forceFill(['is_active' => false])->save();
                }
            }

            foreach ($submitted as $resourceId => $attributes) {
                /** @var SchedulingResource $resource */
                $resource = $resourceModels->get($resourceId);
                /** @var SchedulingHostResource|null $row */
                $row = $existing->get($resourceId);

                if ($row instanceof SchedulingHostResource
                    && ! $this->hostResourceIsEditable($row)
                ) {
                    throw new DomainException(
                        'Provider- and system-owned host resource capacities are read-only in CRM configuration.',
                    );
                }

                if ($attributes['is_active'] && (
                    $resource->trashed()
                    || $resource->status !== SchedulingResource::STATUS_ACTIVE
                )) {
                    throw new DomainException(
                        'Only active scheduling resources may receive an active host capacity.',
                    );
                }

                if (! $row instanceof SchedulingHostResource && ! $attributes['is_active']) {
                    continue;
                }

                if (! $row instanceof SchedulingHostResource) {
                    $row = new SchedulingHostResource([
                        'scheduling_host_id' => $lockedHost->getKey(),
                        'scheduling_resource_id' => $resourceId,
                        'source' => SchedulingHostResource::SOURCE_MANUAL,
                    ]);
                }

                $capacity = $attributes['capacity'];

                if ($capacity === null) {
                    $capacity = $row->exists
                        ? max(1, (int) $row->capacity)
                        : 1;
                }

                $row->forceFill([
                    'capacity' => $capacity,
                    'is_active' => $attributes['is_active'],
                    'sort_order' => $attributes['sort_order'],
                ])->save();
            }

            $this->saveWithVersionBump($lockedHost);

            return $lockedHost->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     */
    public function syncServiceRequirements(
        BookableService $service,
        array $resources,
        string $expectedUpdatedAt,
    ): BookableService {
        return DB::transaction(function () use (
            $service,
            $resources,
            $expectedUpdatedAt,
        ): BookableService {
            $lockedService = BookableService::withTrashed()
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedService->trashed()) {
                throw new DomainException(
                    'Archived bookable services cannot receive resource configuration changes.',
                );
            }

            $this->assertFresh($lockedService, $expectedUpdatedAt, 'Bookable service');
            $submitted = $this->normalizedServiceRequirements($resources);
            $resourceModels = $this->lockedResources(array_keys($submitted));
            $existing = BookableServiceResourceRequirement::query()
                ->where('bookable_service_id', $lockedService->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (BookableServiceResourceRequirement $row): int =>
                    (int) $row->scheduling_resource_id
                );

            foreach ($existing as $resourceId => $row) {
                if ($row->source === BookableServiceResourceRequirement::SOURCE_MANUAL
                    && ! array_key_exists((int) $resourceId, $submitted)
                    && $row->is_active
                ) {
                    $row->forceFill(['is_active' => false])->save();
                }
            }

            foreach ($submitted as $resourceId => $attributes) {
                /** @var SchedulingResource $resource */
                $resource = $resourceModels->get($resourceId);
                /** @var BookableServiceResourceRequirement|null $row */
                $row = $existing->get($resourceId);

                if ($row instanceof BookableServiceResourceRequirement
                    && ! $this->serviceRequirementIsEditable($row)
                ) {
                    throw new DomainException(
                        'Provider- and system-owned service resource requirements are read-only in CRM configuration.',
                    );
                }

                if ($attributes['is_active'] && (
                    $resource->trashed()
                    || $resource->status !== SchedulingResource::STATUS_ACTIVE
                )) {
                    throw new DomainException(
                        'Only active scheduling resources may receive an active service requirement.',
                    );
                }

                if (! $row instanceof BookableServiceResourceRequirement
                    && ! $attributes['is_active']
                ) {
                    continue;
                }

                if (! $row instanceof BookableServiceResourceRequirement) {
                    $row = new BookableServiceResourceRequirement([
                        'bookable_service_id' => $lockedService->getKey(),
                        'scheduling_resource_id' => $resourceId,
                        'source' => BookableServiceResourceRequirement::SOURCE_MANUAL,
                    ]);
                }

                $quantity = $attributes['quantity'];

                if ($quantity === null) {
                    $quantity = $row->exists
                        ? max(1, (int) $row->quantity)
                        : 1;
                }

                $row->forceFill([
                    'quantity' => $quantity,
                    'is_active' => $attributes['is_active'],
                    'sort_order' => $attributes['sort_order'],
                ])->save();
            }

            $this->saveWithVersionBump($lockedService);

            return $lockedService->refresh();
        });
    }

    public function resourceIsEditable(SchedulingResource $resource): bool
    {
        return ! $resource->trashed()
            && $resource->source === SchedulingResource::SOURCE_MANUAL;
    }

    public function hostResourceIsEditable(SchedulingHostResource $row): bool
    {
        return $row->source === SchedulingHostResource::SOURCE_MANUAL;
    }

    public function serviceRequirementIsEditable(
        BookableServiceResourceRequirement $row,
    ): bool {
        return $row->source === BookableServiceResourceRequirement::SOURCE_MANUAL;
    }

    private function assertResourceEditable(SchedulingResource $resource): void
    {
        if (! $this->resourceIsEditable($resource)) {
            throw new DomainException(
                'Provider-, system-, or deleted scheduling resources are read-only in CRM configuration.',
            );
        }
    }

    private function assertResourceHasNoActiveAssociations(
        SchedulingResource $resource,
    ): void {
        $activeHostCapacity = SchedulingHostResource::query()
            ->where('scheduling_resource_id', $resource->getKey())
            ->where('is_active', true)
            ->exists();
        $activeServiceRequirement = BookableServiceResourceRequirement::query()
            ->where('scheduling_resource_id', $resource->getKey())
            ->where('is_active', true)
            ->exists();

        if ($activeHostCapacity || $activeServiceRequirement) {
            throw new DomainException(
                'Deactivate every host capacity and service requirement before archiving this resource.',
            );
        }
    }

    /**
     * @param array<int, int> $resourceIds
     * @return \Illuminate\Database\Eloquent\Collection<int, SchedulingResource>
     */
    private function lockedResources(array $resourceIds): Collection
    {
        if ($resourceIds === []) {
            return new Collection();
        }

        $resources = SchedulingResource::withTrashed()
            ->whereKey($resourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SchedulingResource $resource): int =>
                (int) $resource->getKey()
            );

        if ($resources->count() !== count(array_unique($resourceIds))) {
            throw new DomainException(
                'One or more selected scheduling resources no longer exist.',
            );
        }

        return $resources;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<int, array{is_active: bool, capacity: int|null, sort_order: int}>
     */
    private function normalizedHostResources(array $resources): array
    {
        $normalized = [];

        foreach ($resources as $attributes) {
            if (! is_array($attributes)) {
                throw new InvalidArgumentException(
                    'Host resource capacities must be arrays.',
                );
            }

            $resourceId = $this->positiveInteger(
                $attributes['scheduling_resource_id'] ?? null,
                'scheduling resource ID',
            );

            if (array_key_exists($resourceId, $normalized)) {
                throw new InvalidArgumentException(
                    'Each scheduling resource may appear only once in a host capacity submission.',
                );
            }

            $active = (bool) ($attributes['is_active'] ?? false);
            $capacity = $this->nullablePositiveInteger(
                $attributes['capacity'] ?? null,
                'host resource capacity',
            );

            if ($active && $capacity === null) {
                throw new InvalidArgumentException(
                    'An active host resource capacity requires a positive capacity.',
                );
            }

            $normalized[$resourceId] = [
                'is_active' => $active,
                'capacity' => $capacity,
                'sort_order' => $this->nonNegativeInteger(
                    $attributes['sort_order'] ?? 0,
                    'host resource sort order',
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<int, array{is_active: bool, quantity: int|null, sort_order: int}>
     */
    private function normalizedServiceRequirements(array $resources): array
    {
        $normalized = [];

        foreach ($resources as $attributes) {
            if (! is_array($attributes)) {
                throw new InvalidArgumentException(
                    'Service resource requirements must be arrays.',
                );
            }

            $resourceId = $this->positiveInteger(
                $attributes['scheduling_resource_id'] ?? null,
                'scheduling resource ID',
            );

            if (array_key_exists($resourceId, $normalized)) {
                throw new InvalidArgumentException(
                    'Each scheduling resource may appear only once in a service requirement submission.',
                );
            }

            $active = (bool) ($attributes['is_active'] ?? false);
            $quantity = $this->nullablePositiveInteger(
                $attributes['quantity'] ?? null,
                'service resource quantity',
            );

            if ($active && $quantity === null) {
                throw new InvalidArgumentException(
                    'An active service resource requirement requires a positive quantity.',
                );
            }

            $normalized[$resourceId] = [
                'is_active' => $active,
                'quantity' => $quantity,
                'sort_order' => $this->nonNegativeInteger(
                    $attributes['sort_order'] ?? 0,
                    'service requirement sort order',
                ),
            ];
        }

        return $normalized;
    }

    private function saveWithVersionBump(Model $model): void
    {
        $current = $model->getOriginal('updated_at');
        $current = $current !== null
            ? CarbonImmutable::parse($current)->utc()
            : null;
        $now = CarbonImmutable::now('UTC');
        $next = $current instanceof CarbonImmutable && ! $now->greaterThan($current)
            ? $current->addSecond()
            : $now;

        $model->forceFill(['updated_at' => $next])->saveQuietly();
    }

    private function assertFresh(
        Model $model,
        string $expectedUpdatedAt,
        string $label,
    ): void {
        $expectedUpdatedAt = trim($expectedUpdatedAt);

        if ($expectedUpdatedAt === '') {
            throw new InvalidArgumentException(
                "{$label} updates require the current record version.",
            );
        }

        try {
            $expected = CarbonImmutable::parse($expectedUpdatedAt)->utc();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                "{$label} record version is invalid.",
                previous: $exception,
            );
        }

        $actual = $model->getAttribute('updated_at');

        if ($actual === null
            || ! CarbonImmutable::instance($actual)->utc()->equalTo($expected)
        ) {
            throw new DomainException(
                "{$label} changed after this form was loaded. Refresh and try again.",
            );
        }
    }

    private function requiredKey(mixed $value): string
    {
        $value = $this->requiredString($value, 'resource key');

        if (preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Scheduling resource keys must use lowercase letters, numbers, hyphens, or underscores.',
            );
        }

        return $value;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("A non-empty {$label} is required.");
        }

        return trim($value);
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException("{$label} must be at least 1.");
        }

        return (int) $value;
    }

    private function nullablePositiveInteger(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInteger($value, $label);
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return (int) $value;
    }

    private function resourceStatus(mixed $value): string
    {
        $value = $this->requiredString($value, 'resource status');

        if (! in_array($value, [
            SchedulingResource::STATUS_ACTIVE,
            SchedulingResource::STATUS_INACTIVE,
            SchedulingResource::STATUS_ARCHIVED,
        ], true)) {
            throw new InvalidArgumentException(
                "Scheduling resource status [{$value}] is invalid.",
            );
        }

        return $value;
    }
}