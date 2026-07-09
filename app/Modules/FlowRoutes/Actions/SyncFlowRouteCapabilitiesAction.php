<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use Throwable;

class SyncFlowRouteCapabilitiesAction
{
    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilityRegistry,
        private readonly PointHandlerRegistry $pointHandlerRegistry,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     customized_skipped: int,
     *     unavailable_handlers: int,
     *     errors: array<int, string>
     * }
     */
    public function handle(): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'customized_skipped' => 0,
            'unavailable_handlers' => 0,
            'errors' => [],
        ];

        try {
            $definitions = $this->capabilityRegistry->definitions();
        } catch (Throwable $exception) {
            $result['errors'][] = $exception->getMessage();

            return $result;
        }

        foreach ($definitions as $definition) {
            try {
                $this->syncDefinition($definition, $result);
            } catch (Throwable $exception) {
                $result['errors'][] = "Automation capability [{$definition->key}] could not be synced: {$exception->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function syncDefinition(AutomationCapabilityDefinition $definition, array &$result): void
    {
        $capability = FlowRouteCapability::query()->firstOrNew([
            'key' => $definition->key,
        ]);

        if ($capability->exists && $capability->is_customized) {
            $result['customized_skipped']++;

            return;
        }

        $wasRecentlyCreated = ! $capability->exists;
        $handlerAvailable = $this->pointHandlerRegistry->has($definition->pointType);

        $capability->forceFill([
            'key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'capability_type' => $definition->capabilityType,
            'point_type' => $definition->pointType,
            'handler_key' => $definition->handlerKey,
            'event_key' => $definition->eventKey,
            'action_key' => $definition->actionKey,
            'name' => $definition->name,
            'description' => $definition->description,
            'category' => $definition->category,
            'surface' => $definition->surface,
            'supported_subjects' => $definition->supportedSubjects,
            'required_modules' => $definition->requiredModules,
            'input_schema' => $definition->inputSchema,
            'output_schema' => $definition->outputSchema,
            'available_fields' => $definition->availableFields,
            'defaults' => $definition->defaults,
            'is_active' => $definition->isActive,
            'source' => 'module_registry',
            'source_version' => $definition->sourceVersion,
            'meta' => array_replace_recursive($capability->meta ?? [], $definition->meta, [
                'runtime' => [
                    'handler_available_at_sync' => $handlerAvailable,
                ],
            ]),
        ])->save();

        $result[$wasRecentlyCreated ? 'created' : 'updated']++;

        if (! $handlerAvailable) {
            $result['unavailable_handlers']++;
        }
    }
}
