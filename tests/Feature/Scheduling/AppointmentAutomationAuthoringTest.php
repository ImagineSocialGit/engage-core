<?php

namespace Tests\Feature\Scheduling;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationAutomationPointAuthoringContributor;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentTaskAutomationPointAuthoringContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentAutomationAuthoringTest extends TestCase
{
    use RefreshDatabase;

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
}