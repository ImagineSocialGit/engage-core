<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableScheduling();
    }

    public function test_dedicated_service_and_staff_routes_require_authentication_and_enabled_module(): void
    {
        $service = BookableService::factory()->create();

        $this->get(route('crm.scheduling.configuration.services.index'))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.services.staff.edit', $service))
            ->assertRedirect(route('login'));
        $this->get(route('crm.scheduling.configuration.staff.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.staff.edit', $service))
            ->assertNotFound();
        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.staff.index'))
            ->assertNotFound();
    }

    public function test_service_setup_is_split_into_readiness_details_and_staff_workspaces(): void
    {
        $user = User::factory()->create();
        $host = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
        ]);
        $service = BookableService::factory()->create([
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'status' => BookableService::STATUS_ACTIVE,
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'remote_method' => BookableService::REMOTE_METHOD_PHONE,
            'in_person_arrangement' => null,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'location_details' => [
                'label' => 'Legacy phone display label',
                'instructions' => 'Bring relevant documents.',
            ],
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => $host->getKey(),
            'is_active' => true,
            'capacity_override' => 2,
            'sort_order' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.edit')
            ->assertViewHas('service', fn (BookableService $viewService): bool =>
                $viewService->is($service)
            )
            ->assertViewHas('serviceEditable', true)
            ->assertSee('data-scheduling-service-hub', false)
            ->assertSee('data-scheduling-service-readiness', false)
            ->assertSee('data-scheduling-config-card="details"', false)
            ->assertSee('data-scheduling-config-card="availability"', false)
            ->assertSee('data-scheduling-config-card="staff"', false)
            ->assertSee(
                route('crm.scheduling.configuration.services.details.edit', $service),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.services.staff.edit', $service),
                false,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.details')
            ->assertViewHas('serviceEditable', true)
            ->assertSee('data-scheduling-service-details="'.$service->getKey().'"', false)
            ->assertSee(
                'data-configuration-service-details-update="'.$service->getKey().'"',
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.services.update', $service),
                false,
            )
            ->assertSee('name="appointment_method"', false)
            ->assertSee('name="duration_mode"', false)
            ->assertSee('name="is_public"', false)
            ->assertDontSee('name="location_type"', false);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.staff.edit', $service))
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.staff')
            ->assertViewHas('assignmentRows', function (array $rows) use ($host): bool {
                foreach ($rows as $row) {
                    if (($row['id'] ?? null) === $host->getKey()
                        && ($row['active'] ?? false) === true
                        && ($row['capacity_override'] ?? null) === 2
                    ) {
                        return true;
                    }
                }

                return false;
            })
            ->assertSee('data-scheduling-service-staff-workspace="'.$service->getKey().'"', false)
            ->assertSee('data-service-assignment-form="'.$service->getKey().'"', false)
            ->assertSee('data-assignment-host-id="'.$host->getKey().'"', false)
            ->assertSee(
                route('crm.scheduling.configuration.services.hosts.update', $service),
                false,
            );
    }

    public function test_provider_owned_service_uses_readiness_hub_and_read_only_details_workspace(): void
    {
        $service = BookableService::factory()->create([
            'source' => 'provider',
            'provider' => 'calendar_provider',
            'external_id' => 'service-123',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertOk()
            ->assertViewHas('serviceEditable', false)
            ->assertSee('Managed externally');

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.details.edit', $service))
            ->assertOk()
            ->assertViewIs('crm.scheduling.services.details')
            ->assertViewHas('serviceEditable', false)
            ->assertSee('data-configuration-read-only="service"', false)
            ->assertDontSee(
                'data-configuration-service-details-update="'.$service->getKey().'"',
                false,
            );
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }
}