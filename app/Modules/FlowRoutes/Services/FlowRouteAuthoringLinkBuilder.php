<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use InvalidArgumentException;

final class FlowRouteAuthoringLinkBuilder
{
    public function __construct(
        private readonly AutomationTriggerAuthoringRegistry $triggers,
    ) {}

    /**
     * Build the shared cross-surface entry link for creating a Flow Route or
     * Automatic behavior with a registered trigger already selected.
     *
     * @param array<string, mixed> $triggerValues
     */
    public function createUrl(
        string $triggerAuthoringKey,
        array $triggerValues = [],
        ?string $name = null,
        string $kind = FlowRoute::AUTHORING_KIND_ROUTE,
        ?string $starterCapabilityKey = null,
    ): string {
        $triggerAuthoringKey = trim($triggerAuthoringKey);

        if (! $this->triggers->available($triggerAuthoringKey)) {
            throw new InvalidArgumentException(
                "Automation trigger [{$triggerAuthoringKey}] is not available for authoring.",
            );
        }

        $kind = in_array($kind, FlowRoute::AUTHORING_KINDS, true)
            ? $kind
            : FlowRoute::AUTHORING_KIND_ROUTE;

        $parameters = [
            'create' => 1,
            'create_kind' => $kind,
            'trigger_authoring_key' => $triggerAuthoringKey,
        ];

        foreach ($this->triggerFieldNames($triggerAuthoringKey) as $fieldName) {
            if (! array_key_exists($fieldName, $triggerValues)) {
                continue;
            }

            $value = $this->queryValue($triggerValues[$fieldName]);

            if ($value !== null) {
                $parameters[$fieldName] = $value;
            }
        }

        $name = is_string($name) ? trim($name) : '';

        if ($name !== '') {
            $parameters['create_name'] = mb_substr($name, 0, 255);
        }

        $starterCapabilityKey = is_string($starterCapabilityKey)
            ? trim($starterCapabilityKey)
            : '';

        if ($starterCapabilityKey !== '') {
            $parameters['starter_capability_key'] = $starterCapabilityKey;
        }

        return route('crm.flow-routes.index', $parameters);
    }

    public function editUrl(
        FlowRoute|int $route,
        ?string $addCapabilityKey = null,
    ): string {
        $routeId = $route instanceof FlowRoute
            ? (int) $route->getKey()
            : $route;

        $parameters = [
            'edit_route' => $routeId,
        ];

        $addCapabilityKey = is_string($addCapabilityKey)
            ? trim($addCapabilityKey)
            : '';

        if ($addCapabilityKey !== '') {
            $parameters['add_capability'] = $addCapabilityKey;
        }

        return route('crm.flow-routes.index', $parameters);
    }

    /** @return array<int, string> */
    public function triggerFieldNames(string $triggerAuthoringKey): array
    {
        return collect($this->triggers->fields($triggerAuthoringKey))
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (mixed $name): string => trim((string) $name))
            ->unique()
            ->values()
            ->all();
    }

    private function queryValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }
}