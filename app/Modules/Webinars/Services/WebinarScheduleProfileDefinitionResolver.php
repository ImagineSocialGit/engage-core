<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;

class WebinarScheduleProfileDefinitionResolver
{
    public function __construct(
        private readonly WebinarScheduleProfileResolver $profileResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
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
                profile: $profile,
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

            $messageArea = $this->messageAreaRegistry->areaForScheduleItem($item);

            if (! $messageArea?->enabled) {
                continue;
            }

            $schedule = is_array($item->schedule) ? $item->schedule : null;
            $conditions = is_array($item->conditions) ? $item->conditions : [];

            $resolvedDefinition = array_replace($definition, [
                'resolved_behavior' => [
                    'timing' => $item->timing,
                    'schedule' => $schedule,
                    'conditions' => $conditions,
                    'skip_when_join_clicked' => (bool) data_get($item->meta, 'skip_when_join_clicked', false),
                ],
                'behavior_owner' => $item,
            ]);

            $resolvedDefinition['meta'] = array_replace_recursive(
                is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
                [
                    'webinar_schedule_profile' => [
                        'id' => $profile->getKey(),
                        'key' => $profile->key,
                        'name' => $profile->name,
                        'message_template_set_key' => $profile->message_template_set_key,
                        'item_id' => $item->getKey(),
                        'item_key' => $item->key,
                        'item_label' => $item->label,
                    ],
                    'webinar_message_area' => [
                        'key' => $messageArea->key,
                        'label' => $messageArea->label,
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
        WebinarScheduleProfile $profile,
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

        $definitionTemplateSetKey = $this->definitionTemplateSetKey(
            $definition,
        );

        if (
            $definitionTemplateSetKey
                !== $this->normalizeSegment(
                    $profile->message_template_set_key ?: 'default',
                )
        ) {
            return false;
        }

        $definitionTemplateKey = $this->definitionTemplateKey($definition);

        if ($definitionTemplateKey === null || $definitionTemplateKey !== $this->normalizeSegment($item->message_template_key)) {
            return false;
        }

        return true;
    }


    /** @param array<string, mixed> $definition */
    private function definitionTemplateSetKey(array $definition): string
    {
        $key = $definition['template_set_key']
            ?? data_get(
                $definition,
                'meta.message_template_assignment.template_set_key',
            )
            ?? data_get($definition, 'meta.message_template_set.key')
            ?? 'default';

        return is_string($key) && trim($key) !== ''
            ? $this->normalizeSegment($key)
            : 'default';
    }

    /** @param array<string, mixed> $definition */
    private function definitionTemplateKey(array $definition): ?string
    {
        $key = $definition['template_key']
            ?? data_get(
                $definition,
                'meta.message_template_assignment.template_key',
            )
            ?? data_get($definition, 'meta.message_template_set.template_key')
            ?? $definition['key']
            ?? data_get($definition, 'meta.message_template_preset.key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);
        $separator = strrpos($key, '.');

        return $this->normalizeSegment(
            $separator === false
                ? $key
                : substr($key, $separator + 1),
        );
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