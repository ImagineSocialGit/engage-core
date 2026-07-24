<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Data\Presets\FlowRoutePointPresetDefinition;
use App\Modules\FlowRoutes\Data\Presets\FlowRoutePresetDefinition;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use InvalidArgumentException;

class FlowRoutePresetDefinitionFactory
{
    private const ROUTE_FIELDS = [
        'contact_status_key',
        'event_key',
        'name',
        'description',
        'version',
        'is_active',
        'source_version',
        'owner_type',
        'owner_id',
        'owner_group',
        'category',
        'role',
        'points',
    ];

    private const POINT_FIELDS = [
        'type',
        'name',
        'description',
        'is_active',
        'definition',
        'cancel_conditions',
    ];

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilityRegistry,
        private readonly AutomationPointDefinitionRegistry $pointDefinitionRegistry,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(
        string $presetKey,
        string $definitionKey,
        array $data,
    ): FlowRoutePresetDefinition {
        $context = "FlowRoute preset [{$definitionKey}]";

        $this->rejectRemovedFields(
            data: $data,
            fields: ['key', 'trigger', 'meta'],
            context: $context,
        );
        $this->rejectUnknownFields($data, self::ROUTE_FIELDS, $context);

        $key = $this->requiredKey($definitionKey, 'FlowRoute definition key');
        $contactStatusKey = $this->nullableString($data['contact_status_key'] ?? null);
        $eventKey = $this->nullableString($data['event_key'] ?? null);

        if ($contactStatusKey !== null && $eventKey !== null) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$key}] cannot define both [contact_status_key] and [event_key]."
            );
        }

        $version = $this->integerField(
            data: $data,
            field: 'version',
            default: 1,
            context: "FlowRoute preset [{$key}]",
        );

        if ($version < 1) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$key}] version must be at least 1."
            );
        }

        $isActive = $this->booleanField(
            data: $data,
            field: 'is_active',
            default: true,
            context: "FlowRoute preset [{$key}]",
        );
        $sourceVersion = $this->nullableString($data['source_version'] ?? null);
        $trigger = $this->trigger($contactStatusKey, $eventKey);
        $pointData = $data['points'] ?? null;

        if (! is_array($pointData) || array_is_list($pointData) || $pointData === []) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$key}] points must be a non-empty keyed map."
            );
        }

        $capabilityKeysByPointType = $this->capabilityKeysByPointType();
        $points = [];
        $normalizedPointKeys = [];

        foreach ($pointData as $pointKey => $point) {
            if (! is_string($pointKey) || trim($pointKey) === '') {
                throw new InvalidArgumentException(
                    "FlowRoute preset [{$key}] contains an invalid point map key."
                );
            }

            if (! is_array($point)) {
                throw new InvalidArgumentException(
                    "FlowRoute preset [{$key}] point [{$pointKey}] must be an object."
                );
            }

            $normalizedPointKey = $this->requiredKey($pointKey, 'FlowRoute point definition key');

            if (isset($normalizedPointKeys[$normalizedPointKey])) {
                throw new InvalidArgumentException(
                    "FlowRoute preset [{$key}] contains duplicate normalized point key [{$normalizedPointKey}]."
                );
            }

            $normalizedPointKeys[$normalizedPointKey] = true;

            $points[] = $this->pointFromArray(
                routeKey: $key,
                pointKey: $normalizedPointKey,
                data: $point,
                index: count($points),
                sourceVersion: $sourceVersion,
                trigger: $trigger,
                capabilityKeysByPointType: $capabilityKeysByPointType,
            );
        }

        $points = $this->deriveGraph($points);

        return new FlowRoutePresetDefinition(
            presetKey: trim($presetKey),
            key: $key,
            contactStatusKey: $contactStatusKey,
            name: $this->requiredString($data['name'] ?? null, "FlowRoute preset [{$key}] name"),
            description: $this->nullableString($data['description'] ?? null),
            version: $version,
            isActive: $isActive,
            sourceVersion: $sourceVersion,
            ownerType: $this->nullableString($data['owner_type'] ?? null),
            ownerId: $this->nullableIntegerField(
                data: $data,
                field: 'owner_id',
                context: "FlowRoute preset [{$key}]",
            ),
            ownerGroup: $this->nullableString($data['owner_group'] ?? null),
            trigger: $trigger,
            points: $points,
            meta: array_filter([
                'category' => $this->nullableString($data['category'] ?? null),
                'default_role' => $this->nullableString($data['role'] ?? null),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $trigger
     * @param array<string, string> $capabilityKeysByPointType
     */
    private function pointFromArray(
        string $routeKey,
        string $pointKey,
        array $data,
        int $index,
        ?string $sourceVersion,
        array $trigger,
        array $capabilityKeysByPointType,
    ): FlowRoutePointPresetDefinition {
        $context = "FlowRoute preset [{$routeKey}] point [{$pointKey}]";

        $this->rejectRemovedFields(
            data: $data,
            fields: [
                'key',
                'capability_key',
                'sort_order',
                'is_start',
                'next_point_key',
                'settings',
                'source_version',
                'meta',
            ],
            context: $context,
        );
        $this->rejectUnknownFields($data, self::POINT_FIELDS, $context);

        $type = $this->requiredKey(
            $data['type'] ?? null,
            "FlowRoute preset [{$routeKey}] point [{$pointKey}] type",
        );

        if (! in_array($type, FlowRoutePointType::values(), true)) {
            throw new InvalidArgumentException(
                "Unsupported FlowRoutePoint type [{$type}] for preset route point [{$pointKey}]."
            );
        }

        $pointDefinition = $this->pointDefinitionRegistry->get($type);

        if ($pointDefinition === null) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$routeKey}] point [{$pointKey}] has no registered definition for point type [{$type}]."
            );
        }

        $capabilityKey = $capabilityKeysByPointType[$type] ?? null;

        if ($capabilityKey === null) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$routeKey}] point [{$pointKey}] has no canonical capability for point type [{$type}]."
            );
        }

        $definition = $data['definition'] ?? [];

        if (! is_array($definition) || (array_is_list($definition) && $definition !== [])) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$routeKey}] point [{$pointKey}] definition must be an object."
            );
        }

        $definition = $this->withContextualDefinitionDefaults(
            type: $type,
            definition: $definition,
            trigger: $trigger,
        );

        $violations = $pointDefinition->schema->validate(
            $definition,
            "flow_route.{$routeKey}.points.{$pointKey}.definition",
        );

        if ($violations !== []) {
            throw new InvalidArgumentException($violations[0]->message);
        }

        $cancelConditions = $data['cancel_conditions'] ?? [];

        if (! is_array($cancelConditions) || ! array_is_list($cancelConditions)) {
            throw new InvalidArgumentException(
                "FlowRoute preset [{$routeKey}] point [{$pointKey}] cancel_conditions must be a list."
            );
        }

        foreach ($cancelConditions as $condition) {
            if (! is_array($condition)) {
                throw new InvalidArgumentException(
                    "FlowRoute preset [{$routeKey}] point [{$pointKey}] cancel_conditions must contain objects."
                );
            }
        }

        return new FlowRoutePointPresetDefinition(
            key: $pointKey,
            type: $type,
            name: $this->nullableString($data['name'] ?? null) ?? $this->nameFromKey($pointKey),
            description: $this->nullableString($data['description'] ?? null),
            capabilityKey: $capabilityKey,
            sortOrder: ($index + 1) * 10,
            isStart: false,
            isActive: $this->booleanField(
                data: $data,
                field: 'is_active',
                default: true,
                context: $context,
            ),
            nextPointKey: null,
            definition: $pointDefinition->schema->normalize($definition),
            cancelConditions: array_values($cancelConditions),
            sourceVersion: $sourceVersion,
        );
    }

    /**
     * @param array<int, FlowRoutePointPresetDefinition> $points
     * @return array<int, FlowRoutePointPresetDefinition>
     */
    private function deriveGraph(array $points): array
    {
        $activeKeys = array_values(array_map(
            static fn (FlowRoutePointPresetDefinition $point): string => $point->key,
            array_filter(
                $points,
                static fn (FlowRoutePointPresetDefinition $point): bool => $point->isActive,
            ),
        ));

        $activePositionByKey = array_flip($activeKeys);

        return array_map(function (FlowRoutePointPresetDefinition $point) use (
            $activeKeys,
            $activePositionByKey,
        ): FlowRoutePointPresetDefinition {
            $activePosition = $activePositionByKey[$point->key] ?? null;
            $isStart = $activePosition === 0;
            $nextPointKey = is_int($activePosition)
                ? ($activeKeys[$activePosition + 1] ?? null)
                : null;

            return new FlowRoutePointPresetDefinition(
                key: $point->key,
                type: $point->type,
                name: $point->name,
                description: $point->description,
                capabilityKey: $point->capabilityKey,
                sortOrder: $point->sortOrder,
                isStart: $isStart,
                isActive: $point->isActive,
                nextPointKey: $nextPointKey,
                definition: $point->definition,
                cancelConditions: $point->cancelConditions,
                sourceVersion: $point->sourceVersion,
            );
        }, $points);
    }

    /**
     * @return array<string, string>
     */
    private function capabilityKeysByPointType(): array
    {
        $keys = [];

        foreach ($this->capabilityRegistry->definitions() as $definition) {
            if (! $definition instanceof AutomationCapabilityDefinition || ! $definition->isActive) {
                continue;
            }

            if (isset($keys[$definition->pointType])) {
                throw new InvalidArgumentException(
                    "Point type [{$definition->pointType}] has more than one active automation capability; compact FlowRoute presets require one canonical capability per point type."
                );
            }

            $keys[$definition->pointType] = $definition->key;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $trigger
     * @return array<string, mixed>
     */
    private function withContextualDefinitionDefaults(
        string $type,
        array $definition,
        array $trigger,
    ): array {
        $triggerType = $trigger['type'] ?? FlowRoute::TRIGGER_MANUAL;
        $triggerKey = $trigger['event_key'] ?? null;
        $reason = is_string($triggerKey) && trim($triggerKey) !== ''
            ? str_replace(['.', '-'], '_', strtolower(trim($triggerKey))).'_event'
            : 'flow_route';

        if ($type === 'change_status') {
            return array_replace_recursive([
                'reason' => $reason,
                'meta' => array_filter([
                    'source' => 'flow_route',
                    'trigger_type' => $triggerType,
                    'event_key' => $triggerKey,
                ], static fn (mixed $value): bool => $value !== null),
            ], $definition);
        }

        if ($type === 'enroll_campaign') {
            return array_replace_recursive([
                'meta' => [
                    'source' => 'flow_route',
                    'reason' => $reason,
                ],
                'start_context' => array_filter([
                    'source' => 'flow_route',
                    'trigger_type' => $triggerType,
                    'event_key' => $triggerKey,
                ], static fn (mixed $value): bool => $value !== null),
                'exit_conditions' => [],
            ], $definition);
        }

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function trigger(?string $contactStatusKey, ?string $eventKey): array
    {
        if ($contactStatusKey !== null) {
            return [];
        }

        if ($eventKey !== null) {
            return [
                'type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
                'event_key' => $eventKey,
            ];
        }

        return [
            'type' => FlowRoute::TRIGGER_MANUAL,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     */
    private function rejectRemovedFields(
        array $data,
        array $fields,
        string $context,
    ): void {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "{$context} must not define removed field [{$field}]."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $allowedFields
     */
    private function rejectUnknownFields(
        array $data,
        array $allowedFields,
        string $context,
    ): void {
        foreach (array_diff(array_keys($data), $allowedFields) as $field) {
            throw new InvalidArgumentException(
                "{$context} contains unsupported field [{$field}]."
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function booleanField(
        array $data,
        string $field,
        bool $default,
        string $context,
    ): bool {
        if (! array_key_exists($field, $data)) {
            return $default;
        }

        if (! is_bool($data[$field])) {
            throw new InvalidArgumentException(
                "{$context} field [{$field}] must be boolean."
            );
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function integerField(
        array $data,
        string $field,
        int $default,
        string $context,
    ): int {
        if (! array_key_exists($field, $data)) {
            return $default;
        }

        if (! is_int($data[$field])) {
            throw new InvalidArgumentException(
                "{$context} field [{$field}] must be an integer."
            );
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function nullableIntegerField(
        array $data,
        string $field,
        string $context,
    ): ?int {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (! is_int($data[$field])) {
            throw new InvalidArgumentException(
                "{$context} field [{$field}] must be an integer or null."
            );
        }

        return $data[$field];
    }

    private function requiredKey(mixed $value, string $field): string
    {
        $value = $this->requiredString($value, $field);

        return trim($value);
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing required {$field}.");
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nameFromKey(string $key): string
    {
        return str($key)->replace(['-', '_'], ' ')->headline()->toString();
    }
}