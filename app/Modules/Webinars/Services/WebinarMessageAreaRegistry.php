<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Data\WebinarMessageAreaDefinition;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class WebinarMessageAreaRegistry
{
    /**
     * @return Collection<string, WebinarMessageAreaDefinition>
     */
    public function all(): Collection
    {
        $configured = config('webinars.message_areas', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('Webinar message-area configuration must be an array.');
        }

        $definitions = collect($configured)
            ->map(function (mixed $definition, mixed $key): WebinarMessageAreaDefinition {
                if (! is_string($key) || trim($key) === '' || ! is_array($definition)) {
                    throw new InvalidArgumentException('Every Webinar message area requires a stable string key and array definition.');
                }

                return WebinarMessageAreaDefinition::fromConfig($key, $definition);
            })
            ->sortBy(fn (WebinarMessageAreaDefinition $definition): array => [
                $definition->sortOrder,
                $definition->key,
            ]);

        $contextOwners = [];

        foreach ($definitions as $definition) {
            foreach ($definition->profileContextKeys as $contextKey) {
                if (isset($contextOwners[$contextKey]) && $contextOwners[$contextKey] !== $definition->key) {
                    throw new InvalidArgumentException(sprintf(
                        'Webinar message-area context key [%s] is owned by both [%s] and [%s].',
                        $contextKey,
                        $contextOwners[$contextKey],
                        $definition->key,
                    ));
                }

                $contextOwners[$contextKey] = $definition->key;
            }
        }

        return $definitions;
    }

    /**
     * @return Collection<string, WebinarMessageAreaDefinition>
     */
    public function enabled(): Collection
    {
        return $this->all()
            ->filter(fn (WebinarMessageAreaDefinition $definition): bool => $definition->enabled);
    }

    public function get(string $key): ?WebinarMessageAreaDefinition
    {
        return $this->all()->get($this->normalizeSegment($key));
    }

    public function isEnabled(string $key): bool
    {
        return $this->get($key)?->enabled === true;
    }

    /**
     * @return array<int, string>
     */
    public function enabledUsageTypes(): array
    {
        return $this->enabled()
            ->flatMap(fn (WebinarMessageAreaDefinition $definition): array => $definition->usageTypes)
            ->unique()
            ->values()
            ->all();
    }

    public function canonicalKeyForContext(string $contextKey): ?string
    {
        $contextKey = $this->normalizeSegment($contextKey);

        foreach ($this->all() as $definition) {
            if ($definition->matchesProfileContext($contextKey)) {
                return $definition->key;
            }
        }

        return null;
    }

    /**
     * @param array<int, string>|null $areaKeys
     * @return array<int, string>|null
     */
    public function normalizeEnabledAreaKeys(?array $areaKeys): ?array
    {
        if ($areaKeys === null) {
            return null;
        }

        $resolved = [];

        foreach ($areaKeys as $areaKey) {
            if (! is_string($areaKey) || trim($areaKey) === '') {
                continue;
            }

            $canonical = $this->canonicalKeyForContext($areaKey);
            $definition = $canonical !== null ? $this->get($canonical) : null;

            if ($definition?->enabled) {
                $resolved[] = $definition->key;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<int, string>|null $areaKeys
     * @return array<int, array<string, mixed>>
     */
    public function filterDefinitions(
        array $definitions,
        ?array $areaKeys = null,
        ?string $surface = null,
    ): array {
        $allowedAreaKeys = $this->normalizeEnabledAreaKeys($areaKeys);

        if ($allowedAreaKeys === []) {
            return [];
        }

        return array_values(array_filter(
            $definitions,
            function (array $definition) use ($allowedAreaKeys, $surface): bool {
                $area = $this->areaForDefinition($definition, $surface);

                if (! $area?->enabled) {
                    return false;
                }

                return $allowedAreaKeys === null
                    || in_array($area->key, $allowedAreaKeys, true);
            },
        ));
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function areaForDefinition(
        array $definition,
        ?string $surface = null,
    ): ?WebinarMessageAreaDefinition {
        $owner = $definition['behavior_owner'] ?? null;
        $profileContextKey = $owner instanceof WebinarScheduleProfileItem
            ? $owner->context_key
            : null;

        if (is_string($profileContextKey) && trim($profileContextKey) !== '') {
            $canonicalKey = $this->canonicalKeyForContext($profileContextKey);

            if ($canonicalKey !== null) {
                $area = $this->get($canonicalKey);

                return $area?->matchesDefinition($definition, $surface, $profileContextKey)
                    ? $area
                    : null;
            }
        }

        foreach ($this->all() as $area) {
            if ($area->matchesDefinition($definition, $surface)) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|WebinarScheduleProfileItem $item
     */
    public function areaForScheduleItem(
        array|WebinarScheduleProfileItem $item,
    ): ?WebinarMessageAreaDefinition {
        $values = $item instanceof WebinarScheduleProfileItem
            ? [
                'context_key' => $item->context_key,
                'purpose' => $item->purpose,
                'scope' => $item->scope,
                'surface' => $item->surface,
                'message_type' => $item->message_type,
                'dispatch_key' => $item->dispatch_key,
            ]
            : $item;

        $contextKey = $values['context_key'] ?? null;

        if (is_string($contextKey) && trim($contextKey) !== '') {
            $canonicalKey = $this->canonicalKeyForContext($contextKey);

            if ($canonicalKey !== null) {
                $area = $this->get($canonicalKey);

                return $area?->matchesScheduleItem($values)
                    ? $area
                    : null;
            }
        }

        foreach ($this->all() as $area) {
            if ($area->matchesScheduleItem($values)) {
                return $area;
            }
        }

        return null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
