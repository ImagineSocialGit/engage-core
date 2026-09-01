<?php

namespace App\Modules\FlowRoutes\Validation;

use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\Data\AutomationCapabilityDefinition;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;

final class FlowRouteCapabilityMaterializationSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
    ) {}

    public function findings(): iterable
    {
        if (! Schema::hasTable('flow_route_capabilities')) {
            return;
        }

        $materializedKeys = FlowRouteCapability::query()
            ->pluck('key')
            ->mapWithKeys(static fn (mixed $key): array => [(string) $key => true])
            ->all();

        // A completely empty catalog belongs to initial installation, where engage:install
        // owns materialization. Once a catalog exists, missing registered definitions are
        // deployment drift and must not silently disappear from Route authoring.
        if ($materializedKeys === []) {
            return;
        }

        foreach ($this->capabilities->definitions() as $key => $definition) {
            if (isset($materializedKeys[$key])) {
                continue;
            }

            yield $this->missingCapabilityFinding($definition);
        }
    }

    private function missingCapabilityFinding(
        AutomationCapabilityDefinition $definition,
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: 'flow_routes.runtime_capability_not_materialized',
            message: "Registered Flow Route capability [{$definition->name}] is not available in the database. Run [php artisan presets:sync] before using or deploying this environment.",
            source: 'flow_route_capabilities',
            path: $definition->key,
            module: 'flow_routes',
            context: [
                'capability_key' => $definition->key,
                'module_key' => $definition->moduleKey,
                'point_type' => $definition->pointType,
                'required_modules' => $definition->requiredModules,
            ],
        );
    }
}