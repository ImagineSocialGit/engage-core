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

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.after-booking.index'))
            ->assertOk()
            ->assertViewIs('crm.scheduling.after-booking');

        $afterBooking = $response->viewData('afterBooking');

        $this->assertIsArray($afterBooking);
        $this->assertSame('simple', $afterBooking['mode']);
        $this->assertTrue($afterBooking['workflow_available']);
        $this->assertTrue($afterBooking['tasks_available']);
        $this->assertContains(
            $status->key,
            collect($afterBooking['status_options'])->pluck('value')->all(),
        );
        $this->assertContains(
            $template->key,
            collect($afterBooking['task_template_options'])->pluck('value')->all(),
        );

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

    public function test_flow_routes_workspace_builds_registered_scheduling_handoffs_without_flow_route_view_changes(): void
    {
        $this->enableModules([
            'scheduling',
            'tasks',
            'workflow',
            'flow_routes',
        ]);

        $service = BookableService::factory()->create([
            'key' => 'strategy_session',
            'name' => 'Strategy Session',
        ]);

        $globalRoute = FlowRoute::factory()
            ->forAutomationEvent(AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED)
            ->create([
                'name' => 'All scheduled appointments',
                'meta' => [
                    'authoring' => ['kind' => FlowRoute::AUTHORING_KIND_ROUTE],
                    'definition' => ['entry_conditions' => []],
                ],
            ]);

        $serviceRoute = FlowRoute::factory()
            ->forAutomationEvent(AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED)
            ->create([
                'name' => 'Strategy Session follow up',
                'meta' => [
                    'authoring' => ['kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR],
                    'definition' => [
                        'entry_conditions' => [[
                            'source' => 'execution_meta',
                            'path' => AppointmentAutomationTriggerAuthoringContributor::BOOKABLE_SERVICE_EVENT_PATH,
                            'operator' => 'equals',
                            'value' => $service->getKey(),
                        ]],
                    ],
                ],
            ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.after-booking.index'))
            ->assertOk();

        $afterBooking = $response->viewData('afterBooking');

        $this->assertSame('flow_routes', $afterBooking['mode']);
        $this->assertContains(
            $globalRoute->getKey(),
            collect($afterBooking['global']['automations'])->pluck('id')->all(),
        );

        $serviceItem = collect($afterBooking['services'])->first(
            fn (array $item): bool =>
                (int) $item['service']->getKey() === (int) $service->getKey(),
        );

        $this->assertIsArray($serviceItem);
        $this->assertContains(
            $serviceRoute->getKey(),
            collect($serviceItem['automations'])->pluck('id')->all(),
        );

        $customAction = collect($serviceItem['actions'])->firstWhere(
            'key',
            'flow_routes.custom',
        );

        $this->assertIsArray($customAction);

        parse_str(
            (string) parse_url($customAction['url'], PHP_URL_QUERY),
            $query,
        );

        $this->assertSame(
            AppointmentAutomationTriggerAuthoringContributor::KEY,
            $query['trigger_authoring_key'] ?? null,
        );
        $this->assertSame(
            AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED,
            $query['appointment_event_key'] ?? null,
        );
        $this->assertSame(
            (string) $service->getKey(),
            (string) ($query['bookable_service_id'] ?? ''),
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