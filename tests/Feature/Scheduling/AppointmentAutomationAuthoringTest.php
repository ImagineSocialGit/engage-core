<?php

namespace Tests\Feature\Scheduling;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRouteEditorCatalog;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointAuthoringContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AppointmentAutomationAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSchedulingAutomationIntegrations();
    }

    public function test_appointment_points_are_available_only_for_appointment_routes(): void
    {
        TaskTemplate::factory()->create(['key' => 'appointment.prep']);

        $appointmentRoute = FlowRoute::factory()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'appointment.scheduled',
        ]);
        $statusRoute = FlowRoute::factory()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => null,
        ]);

        $task = app(AppointmentTaskAutomationPointAuthoringContributor::class);
        $notification = app(AppointmentHostNotificationAutomationPointAuthoringContributor::class);

        $this->assertTrue($task->available('create_appointment_task', new AutomationPointAuthoringContext(container: $appointmentRoute)));
        $this->assertTrue($notification->available('notify_appointment_host', new AutomationPointAuthoringContext(container: $appointmentRoute)));
        $this->assertFalse($task->available('create_appointment_task', new AutomationPointAuthoringContext(container: $statusRoute)));
        $this->assertFalse($notification->available('notify_appointment_host', new AutomationPointAuthoringContext(container: $statusRoute)));
    }

    public function test_canonical_sync_exposes_appointment_points_through_the_route_editor_catalog(): void
    {
        TaskTemplate::factory()->create(['key' => 'appointment.prep']);

        $route = FlowRoute::factory()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'appointment.scheduled',
        ]);

        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $this->assertSame([], $result['errors']);

        $capabilities = app(FlowRouteEditorCatalog::class)
            ->availableCapabilities($route)
            ->keyBy('key');

        $this->assertTrue($capabilities->has('scheduling.create_appointment_task'));
        $this->assertTrue($capabilities->has('scheduling.notify_appointment_host'));
        $this->assertSame(
            'scheduling',
            data_get($capabilities->get('scheduling.notify_appointment_host'), 'module_key'),
        );
    }

    public function test_authoring_builds_signed_offsets_and_host_assignment(): void
    {
        TaskTemplate::factory()->create(['key' => 'appointment.prep']);
        $route = FlowRoute::factory()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'appointment.scheduled',
        ]);
        $context = new AutomationPointAuthoringContext(container: $route);

        $task = app(AppointmentTaskAutomationPointAuthoringContributor::class)->buildDefinition(
            'create_appointment_task',
            [
                'task_template_key' => 'appointment.prep',
                'timing_direction' => 'before',
                'timing_value' => 2,
                'timing_unit' => 'days',
                'assign_to_host' => '1',
            ],
            $context,
        );

        $notification = app(AppointmentHostNotificationAutomationPointAuthoringContributor::class)->buildDefinition(
            'notify_appointment_host',
            [
                'subject' => 'Prepare for appointment',
                'message' => 'Review the file.',
                'timing_direction' => 'before',
                'timing_value' => 4,
                'timing_unit' => 'hours',
            ],
            $context,
        );

        $this->assertSame(-2880, $task['offset_minutes']);
        $this->assertTrue($task['assign_to_host']);
        $this->assertSame(-240, $notification['offset_minutes']);
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
}