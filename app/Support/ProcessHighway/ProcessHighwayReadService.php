<?php

namespace App\Support\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessHighwayReadService
{
    public const CONTRIBUTOR_TAG = 'process_highway.contributors';

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $processes = collect();

        foreach ($this->container->tagged(self::CONTRIBUTOR_TAG) as $contributor) {
            if (! $contributor instanceof ProcessHighwayContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Process Highway contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    ProcessHighwayContributor::class,
                ));
            }

            foreach ($contributor->processes() as $process) {
                if (! is_array($process)) {
                    throw new InvalidArgumentException(sprintf(
                        'Process Highway contributor [%s] returned a non-array process description.',
                        $contributor::class,
                    ));
                }

                $normalized = $this->normalizeProcess($process);

                if ($normalized !== null) {
                    $processes->push($normalized);
                }
            }
        }

        $processes = $processes
            ->sortBy([
                ['category_priority', 'asc'],
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        $groups = $processes
            ->groupBy('category')
            ->map(function (Collection $items, string $key): array {
                $first = $items->first();

                return [
                    'key' => $key,
                    'label' => (string) ($first['category_label'] ?? Str::headline($key)),
                    'priority' => (int) ($first['category_priority'] ?? 100),
                    'processes' => $items->values(),
                ];
            })
            ->sortBy('priority')
            ->values();

        return [
            'process_count' => $processes->count(),
            'source_count' => $processes
                ->pluck('source_key')
                ->filter()
                ->unique()
                ->count(),
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string, mixed> $process
     * @return array<string, mixed>|null
     */
    private function normalizeProcess(array $process): ?array
    {
        $name = $this->string($process['name'] ?? null);
        $sourceKey = $this->string($process['source_key'] ?? null);
        $category = $this->string($process['category'] ?? null);

        if ($name === null || $sourceKey === null || $category === null) {
            return null;
        }

        return [
            'source_key' => $sourceKey,
            'source_label' => $this->string($process['source_label'] ?? null)
                ?? Str::headline($sourceKey),
            'key' => $this->string($process['key'] ?? null) ?? $name,
            'name' => $name,
            'description' => $this->string($process['description'] ?? null) ?? '',
            'category' => $category,
            'category_label' => $this->string($process['category_label'] ?? null)
                ?? Str::headline($category),
            'category_priority' => (int) ($process['category_priority'] ?? 100),
            'sort_order' => (int) ($process['sort_order'] ?? 100),
            'state' => $this->string($process['state'] ?? null) ?? 'configured',
            'state_label' => $this->string($process['state_label'] ?? null) ?? 'Configured',
            'starts_when' => $this->string($process['starts_when'] ?? null)
                ?? 'The owning feature starts this process.',
            'steps' => $this->arrayList($process['steps'] ?? []),
            'outcomes' => $this->stringList($process['outcomes'] ?? []),
            'details' => $this->arrayList($process['details'] ?? []),
            'attributes' => is_array($process['attributes'] ?? null)
                ? $process['attributes']
                : [],
            'edit_url' => $this->string($process['edit_url'] ?? null),
            'edit_label' => $this->string($process['edit_label'] ?? null) ?? 'Open',
        ];
    }

    /** @return array<int, mixed> */
    private function arrayList(mixed $value): array
    {
        return is_array($value)
            ? array_values($value)
            : [];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $value,
        )));
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}