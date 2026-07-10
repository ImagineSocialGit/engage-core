<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;

class WebinarScheduleProfileDefinitionResolver
{
    public function __construct(
        private readonly WebinarScheduleProfileResolver $profileResolver,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param string|array<int, string> $dispatchKeys
     * @return array<int, array<string, mixed>>
     */
    public function applyForWebinar(
        ?Webinar $webinar,
        array $definitions,
        string|array $dispatchKeys,
        ?string $surface = null,
    ): array {
        $profile = $this->profileResolver->resolveForWebinar($webinar);

        if (! $profile instanceof WebinarScheduleProfile) {
            return [];
        }

        return $this->applyProfile(
            profile: $profile,
            definitions: $definitions,
            dispatchKeys: $dispatchKeys,
            surface: $surface,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param string|array<int, string> $dispatchKeys
     * @return array<int, array<string, mixed>>
     */
    public function applyProfile(
        WebinarScheduleProfile $profile,
        array $definitions,
        string|array $dispatchKeys,
        ?string $surface = null,
    ): array {
        $dispatchKeys = $this->normalizeList(is_string($dispatchKeys) ? [$dispatchKeys] : $dispatchKeys);

        if ($dispatchKeys === []) {
            return [];
        }

        $items = $profile->relationLoaded('items')
            ? $profile->items
            : $profile->items()->get();

        $items = $items
            ->filter(fn (WebinarScheduleProfileItem $item): bool => $item->is_active)
            ->values();

        if ($items->isEmpty()) {
            return [];
        }

        $resolved = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $definitionDispatchKeys = $this->normalizeList($definition['dispatch_keys'] ?? []);

            if (array_intersect($definitionDispatchKeys, $dispatchKeys) === []) {
                continue;
            }

            $item = $items->first(fn (WebinarScheduleProfileItem $item): bool => $this->itemMatchesDefinition(
                item: $item,
                definition: $definition,
                dispatchKeys: $dispatchKeys,
                surface: $surface,
            ));

            if (! $item instanceof WebinarScheduleProfileItem) {
                continue;
            }

            if (! $item->is_enabled) {
                continue;
            }

            $schedule = is_array($item->schedule) ? $item->schedule : null;
            $conditions = is_array($item->conditions) ? $item->conditions : [];

            $resolvedDefinition = array_replace($definition, [
                'timing' => $item->timing,
                'schedule' => $schedule,
                'conditions' => $conditions,
                'skip_when_join_clicked' => (bool) data_get($item->meta, 'skip_when_join_clicked', false),
                'behavior_owner' => $item,
            ]);

            $resolvedDefinition['meta'] = array_replace_recursive(
                is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
                [
                    'webinar_schedule_profile' => [
                        'id' => $profile->getKey(),
                        'key' => $profile->key,
                        'name' => $profile->name,
                        'item_id' => $item->getKey(),
                        'item_key' => $item->key,
                        'item_label' => $item->label,
                    ],
                ],
            );

            $resolved[] = $resolvedDefinition;
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $dispatchKeys
     */
    private function itemMatchesDefinition(
        WebinarScheduleProfileItem $item,
        array $definition,
        array $dispatchKeys,
        ?string $surface,
    ): bool {
        if ($surface !== null && $item->surface !== null && $this->normalizeSegment($item->surface) !== $this->normalizeSegment($surface)) {
            return false;
        }

        foreach (['channel', 'purpose', 'scope', 'message_type'] as $key) {
            if ($this->normalizeSegment((string) ($definition[$key] ?? '')) !== $this->normalizeSegment((string) $item->{$key})) {
                return false;
            }
        }

        if (! in_array($this->normalizeSegment($item->dispatch_key), $dispatchKeys, true)) {
            return false;
        }

        $definitionDispatchKeys = $this->normalizeList($definition['dispatch_keys'] ?? []);

        if (! in_array($this->normalizeSegment($item->dispatch_key), $definitionDispatchKeys, true)) {
            return false;
        }

        if (is_string($item->source_config_path) && trim($item->source_config_path) !== '') {
            $definitionSourceConfigPath = $this->definitionSourceConfigPath($definition);

            return $definitionSourceConfigPath !== null
                && trim($item->source_config_path) === $definitionSourceConfigPath;
        }

        return true;
    }


    /**
     * @param array<string, mixed> $definition
     */
    private function definitionSourceConfigPath(array $definition): ?string
    {
        $sourceConfigPath = $definition['source_config_path']
            ?? data_get($definition, 'meta.seed.config_path')
            ?? data_get($definition, 'meta.message_template_preset.source_config_path')
            ?? $definition['config_path']
            ?? null;

        return is_string($sourceConfigPath) && trim($sourceConfigPath) !== ''
            ? trim($sourceConfigPath)
            : null;
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function normalizeList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? $this->normalizeSegment($value)
                : null,
            $values,
        ))));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
