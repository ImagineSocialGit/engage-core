<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Scheduling\Automation\AppointmentAutomationTriggerAuthoringContributor;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentAfterBookingWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingAfterBookingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_workspace_uses_stable_fallback_configuration_when_flow_routes_is_unavailable(): void
    {
        $this->enableModules([
            'scheduling',
            'tasks',
            'workflow',
        ]);

        $service = BookableService::factory()->create([
            'key' => 'consultation',
            'name' => 'Consultation',
        ]);
        $status = ContactStatus::query()->create([
            'key' => 'scheduled_follow_up',
            'name' => 'Scheduled Follow Up',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $template = TaskTemplate::factory()->create([
            'key' => 'appointment.follow_up',
            'name' => 'Appointment Follow Up',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(
                route('crm.scheduling.configuration.after-booking.update', $service),
                [
                    'mode' => 'simple',
                    'tag' => 'appointment:booked',
                    'contact_status_key' => $status->key,
                    'task_template_key' => $template->key,
                ],
            )
            ->assertRedirect(route('crm.scheduling.configuration.after-booking.index'));

        $service->refresh();

        $this->assertEqualsCanonicalizing(
            [
                'version' => 1,
                'tag' => 'appointment:booked',
                'contact_status_key' => 'scheduled_follow_up',
                'task_template_key' => 'appointment.follow_up',
            ],
            data_get($service->meta, 'after_booking'),
        );

        $this->actingAs(User::factory()->create())
            ->put(
                route('crm.scheduling.configuration.after-booking.update', $service),
                ['mode' => 'manual'],
            )
            ->assertRedirect(route('crm.scheduling.configuration.after-booking.index'));

        $this->assertNull(
            data_get($service->refresh()->meta, 'after_booking'),
        );
    }


    private function enableModules(array $modules): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            'core',
            ...$modules,
        ])));

        $this->app->register(SchedulingModuleServiceProvider::class, true);
        $this->app->register(IntegrationsModuleServiceProvider::class, true);

        foreach ([
            AppointmentAfterBookingWorkspace::class,
            AutomationCapabilityRegistry::class,
            AutomationTriggerAuthoringRegistry::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}