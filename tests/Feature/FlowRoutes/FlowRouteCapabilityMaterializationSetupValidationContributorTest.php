<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\FlowRoutes\Validation\FlowRouteCapabilityMaterializationSetupValidationContributor;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FlowRouteCapabilityMaterializationSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSchedulingAutomationIntegrations();
    }

    public function test_it_reports_a_registered_scheduling_capability_missing_from_the_persisted_catalog(): void
    {
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $this->assertSame([], $result['errors']);
        $this->assertDatabaseHas('flow_route_capabilities', [
            'key' => 'scheduling.notify_appointment_host',
        ]);

        FlowRouteCapability::query()
            ->where('key', 'scheduling.notify_appointment_host')
            ->delete();

        $finding = collect($this->findings())->firstWhere(
            'code',
            'flow_routes.runtime_capability_not_materialized',
        );

        $this->assertIsArray($finding);
        $this->assertSame(
            'scheduling.notify_appointment_host',
            data_get($finding, 'context.capability_key'),
        );
    }

    public function test_canonical_capability_sync_clears_materialization_findings(): void
    {
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [],
            array_values(array_filter(
                $this->findings(),
                static fn (array $finding): bool => $finding['code'] === 'flow_routes.runtime_capability_not_materialized',
            )),
        );
    }

    private function enableSchedulingAutomationIntegrations(): void
    {
        Config::set('modules.enabled', array_values(array_unique([
            ...(array) config('modules.enabled', []),
            'flow_routes',
            'scheduling',
            'tasks',
            'messaging',
            'internal_notifications',
        ])));

        $this->app->register(IntegrationsModuleServiceProvider::class, true);

        foreach ([
            AutomationActionRegistry::class,
            AutomationCapabilityRegistry::class,
            AutomationPointAuthoringRegistry::class,
            AutomationPointDefinitionRegistry::class,
            PointHandlerRegistry::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function findings(): array
    {
        return array_map(
            static fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(FlowRouteCapabilityMaterializationSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}