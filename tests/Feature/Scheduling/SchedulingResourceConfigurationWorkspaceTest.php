<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Models\SchedulingResourceOccupancy;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingResourceConfigurationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 19:30:00 UTC');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_resource_routes_require_authentication_and_enabled_module(): void
    {
        $this->get(route('crm.scheduling.configuration.resources.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.resources.index'))
            ->assertNotFound();
    }

    public function test_workspace_exposes_structural_resource_host_service_and_effect_contracts(): void
    {
        $user = User::factory()->create();
        $resource = SchedulingResource::query()->create([
            'key' => 'physical_presence',
            'name' => 'Physical presence',
        ]);
        $host = SchedulingHost::factory()->create();
        $service = BookableService::factory()->create();
        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);
        SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 1,
        ]);
        BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.resources.index'))
            ->assertOk()
            ->assertSee('data-scheduling-resource-configuration', false)
            ->assertSee('data-resource-create', false)
            ->assertSee('data-scheduling-resource-id="'.$resource->id.'"', false)
            ->assertSee('data-resource-host-form="'.$host->id.'"', false)
            ->assertSee('data-resource-service-form="'.$service->id.'"', false)
            ->assertSee('data-resource-effect="'.$service->id.':'.$host->id.'"', false)
            ->assertSee(route('crm.scheduling.configuration.resources.store'), false)
            ->assertSee(
                route('crm.scheduling.configuration.resources.update', $resource),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.resources.hosts.update', $host),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.resources.services.update', $service),
                false,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.index'))
            ->assertOk()
            ->assertSee('data-scheduling-resource-configuration-link', false)
            ->assertSee(
                route('crm.scheduling.configuration.resources.index'),
                false,
            );
    }

    public function test_manual_resources_are_created_and_updated_with_immutable_server_owned_identity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.resources.store'),
                $this->resourcePayload([
                    'key' => 'phone_attention',
                    'name' => 'Phone attention',
                    'sort_order' => 20,
                ]),
            )
            ->assertRedirect(route('crm.scheduling.configuration.resources.index'))
            ->assertSessionHasNoErrors();

        $resource = SchedulingResource::query()->sole();

        $this->assertSame('phone_attention', $resource->key);
        $this->assertSame('Phone attention', $resource->name);
        $this->assertSame(SchedulingResource::SOURCE_MANUAL, $resource->source);
        $this->assertSame(20, $resource->sort_order);
        $this->assertNull($resource->meta);

        $originalVersion = $resource->updated_at;

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $resource),
                $this->resourcePayload([
                    'current_version' => $resource->updated_at->toISOString(),
                    'name' => 'Telephone attention',
                    'status' => SchedulingResource::STATUS_INACTIVE,
                    'sort_order' => 30,
                ], includeKey: false),
            )
            ->assertSessionHasNoErrors();

        $resource->refresh();

        $this->assertSame('phone_attention', $resource->key);
        $this->assertSame('Telephone attention', $resource->name);
        $this->assertSame(SchedulingResource::STATUS_INACTIVE, $resource->status);
        $this->assertSame(30, $resource->sort_order);
        $this->assertTrue($resource->updated_at->greaterThan($originalVersion));
    }

    public function test_resource_mutations_reject_internal_provider_and_stale_changes(): void
    {
        $user = User::factory()->create();
        $manual = SchedulingResource::query()->create([
            'key' => 'manual_resource',
            'name' => 'Manual resource',
        ]);
        $provider = SchedulingResource::query()->create([
            'key' => 'provider_resource',
            'name' => 'Provider resource',
            'source' => SchedulingResource::SOURCE_PROVIDER,
        ]);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.resources.store'),
                [
                    ...$this->resourcePayload(['key' => 'forged_resource']),
                    'source' => SchedulingResource::SOURCE_SYSTEM,
                ],
            )
            ->assertSessionHasErrors('resources');

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $manual),
                $this->resourcePayload([
                    'current_version' => $manual->updated_at->toISOString(),
                    'key' => 'changed_resource_key',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('resources');

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $provider),
                $this->resourcePayload([
                    'current_version' => $provider->updated_at->toISOString(),
                    'name' => 'Changed provider resource',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('resources');

        $staleVersion = $manual->updated_at->toISOString();
        $manual->forceFill([
            'name' => 'Concurrent resource change',
            'updated_at' => $manual->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $manual),
                $this->resourcePayload([
                    'current_version' => $staleVersion,
                    'name' => 'Stale resource change',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('resources');

        $this->assertSame('Concurrent resource change', $manual->refresh()->name);
        $this->assertSame('Provider resource', $provider->refresh()->name);
        $this->assertSame(2, SchedulingResource::query()->count());
    }

    public function test_host_capacity_sync_preserves_external_rows_avoids_inactive_bloat_and_bumps_version(): void
    {
        $user = User::factory()->create();
        $host = SchedulingHost::factory()->create();
        $manualResource = SchedulingResource::query()->create([
            'key' => 'vehicle',
            'name' => 'Vehicle',
        ]);
        $unusedResource = SchedulingResource::query()->create([
            'key' => 'room',
            'name' => 'Room',
        ]);
        $providerResource = SchedulingResource::query()->create([
            'key' => 'provider_line',
            'name' => 'Provider line',
            'source' => SchedulingResource::SOURCE_PROVIDER,
        ]);
        $providerRow = SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $providerResource->id,
            'capacity' => 4,
            'source' => SchedulingHostResource::SOURCE_PROVIDER,
        ]);
        $originalVersion = $host->updated_at;

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.hosts.update', $host),
                $this->hostResourcesPayload($host, [
                    [
                        'scheduling_resource_id' => $manualResource->id,
                        'is_active' => true,
                        'capacity' => 2,
                        'sort_order' => 10,
                    ],
                    [
                        'scheduling_resource_id' => $unusedResource->id,
                        'is_active' => false,
                        'capacity' => 1,
                        'sort_order' => 20,
                    ],
                ]),
            )
            ->assertSessionHasNoErrors();

        $host->refresh();
        $manualRow = SchedulingHostResource::query()
            ->where('scheduling_host_id', $host->id)
            ->where('scheduling_resource_id', $manualResource->id)
            ->sole();

        $this->assertTrue($manualRow->is_active);
        $this->assertSame(2, $manualRow->capacity);
        $this->assertSame(SchedulingHostResource::SOURCE_MANUAL, $manualRow->source);
        $this->assertDatabaseMissing('scheduling_host_resources', [
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $unusedResource->id,
        ]);
        $this->assertSame(4, $providerRow->refresh()->capacity);
        $this->assertTrue($providerRow->is_active);
        $this->assertTrue($host->updated_at->greaterThan($originalVersion));

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.hosts.update', $host),
                $this->hostResourcesPayload($host, [[
                    'scheduling_resource_id' => $providerResource->id,
                    'is_active' => false,
                    'capacity' => 4,
                    'sort_order' => 0,
                ]]),
            )
            ->assertSessionHasErrors('resources');

        $this->assertTrue($providerRow->refresh()->is_active);
        $this->assertTrue($manualRow->refresh()->is_active);
    }

    public function test_service_requirement_sync_preserves_external_rows_and_changes_runtime_availability(): void
    {
        $user = User::factory()->create();
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'crew',
            'name' => 'Crew',
        ]);
        $unused = SchedulingResource::query()->create([
            'key' => 'equipment',
            'name' => 'Equipment',
        ]);
        SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 3,
        ]);
        $originalVersion = $service->updated_at;

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.services.update', $service),
                $this->serviceRequirementsPayload($service, [
                    [
                        'scheduling_resource_id' => $resource->id,
                        'is_active' => true,
                        'quantity' => 2,
                        'sort_order' => 10,
                    ],
                    [
                        'scheduling_resource_id' => $unused->id,
                        'is_active' => false,
                        'quantity' => 1,
                        'sort_order' => 20,
                    ],
                ]),
            )
            ->assertSessionHasNoErrors();

        $service->refresh();
        $requirement = BookableServiceResourceRequirement::query()
            ->where('bookable_service_id', $service->id)
            ->where('scheduling_resource_id', $resource->id)
            ->sole();
        $slot = app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
            host: $host,
        )[0];

        $this->assertSame(2, $requirement->quantity);
        $this->assertSame(BookableServiceResourceRequirement::SOURCE_MANUAL, $requirement->source);
        $this->assertDatabaseMissing('bookable_service_resource_requirements', [
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $unused->id,
        ]);
        $this->assertSame(1, $slot->capacity);
        $this->assertSame(1, $slot->remainingCapacity);
        $this->assertTrue($service->updated_at->greaterThan($originalVersion));

        $service->refresh();

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.services.update', $service),
                $this->serviceRequirementsPayload($service, [[
                    'scheduling_resource_id' => $resource->id,
                    'is_active' => false,
                    'quantity' => 2,
                    'sort_order' => 10,
                ]]),
            )
            ->assertSessionHasNoErrors();

        $this->assertFalse($requirement->refresh()->is_active);
        $withoutRequirement = app(SchedulingReadService::class)->availabilityForDate(
            service: $service->refresh(),
            date: CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
            host: $host,
        )[0];
        $this->assertSame(5, $withoutRequirement->capacity);
    }

    public function test_inactive_resources_cannot_be_activated_and_archival_requires_no_active_associations(): void
    {
        $user = User::factory()->create();
        $resource = SchedulingResource::query()->create([
            'key' => 'physical_presence',
            'name' => 'Physical presence',
            'status' => SchedulingResource::STATUS_INACTIVE,
        ]);
        $host = SchedulingHost::factory()->create();
        $service = BookableService::factory()->create();

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.hosts.update', $host),
                $this->hostResourcesPayload($host, [[
                    'scheduling_resource_id' => $resource->id,
                    'is_active' => true,
                    'capacity' => 1,
                    'sort_order' => 0,
                ]]),
            )
            ->assertSessionHasErrors('resources');

        $resource->forceFill(['status' => SchedulingResource::STATUS_ACTIVE])->save();
        $row = SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 1,
        ]);
        BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 1,
        ]);
        $resource->refresh();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $resource),
                $this->resourcePayload([
                    'current_version' => $resource->updated_at->toISOString(),
                    'status' => SchedulingResource::STATUS_ARCHIVED,
                ], includeKey: false),
            )
            ->assertSessionHasErrors('resources');

        SchedulingHostResource::query()
            ->where('scheduling_resource_id', $resource->id)
            ->update(['is_active' => false]);
        BookableServiceResourceRequirement::query()
            ->where('scheduling_resource_id', $resource->id)
            ->update(['is_active' => false]);
        $resource->refresh();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.resources.update', $resource),
                $this->resourcePayload([
                    'current_version' => $resource->updated_at->toISOString(),
                    'status' => SchedulingResource::STATUS_ARCHIVED,
                ], includeKey: false),
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            SchedulingResource::STATUS_ARCHIVED,
            $resource->refresh()->status,
        );
        $this->assertFalse($row->refresh()->is_active);
    }

    public function test_effect_summary_reports_resource_ceiling_and_closed_reason(): void
    {
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'specialist',
            'name' => 'Specialist',
        ]);
        $capacity = SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 4,
        ]);
        BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 2,
        ]);

        $effect = app(SchedulingReadService::class)
            ->resourceConfigurationEffects()
            ->sole();

        $this->assertSame('available', $effect['state']);
        $this->assertSame(2, $effect['resource_ceiling']);
        $this->assertSame($resource->id, $effect['requirements'][0]['resource_id']);
        $this->assertSame(4, $effect['requirements'][0]['host_capacity']);
        $this->assertSame(2, $effect['requirements'][0]['quantity']);

        $capacity->forceFill(['is_active' => false])->save();
        $closed = app(SchedulingReadService::class)
            ->resourceConfigurationEffects()
            ->sole();

        $this->assertSame('closed', $closed['state']);
        $this->assertSame('host_capacity_missing', $closed['reason']);
        $this->assertEmpty(app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
            host: $host,
        ));
    }

    public function test_configuration_changes_do_not_mutate_existing_occupancy_snapshots(): void
    {
        $user = User::factory()->create();
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'vehicle',
            'name' => 'Vehicle',
        ]);
        SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 2,
        ]);
        BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 1,
        ]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => '2026-08-04 09:00:00',
            'ends_at' => '2026-08-04 10:00:00',
        ]);
        $occupancy = SchedulingResourceOccupancy::query()->create([
            'scheduling_resource_id' => $resource->id,
            'scheduling_host_id' => $host->id,
            'appointment_id' => $appointment->id,
            'booking_hold_id' => null,
            'quantity' => 1,
            'occupancy_starts_at' => '2026-08-04 09:00:00',
            'occupancy_ends_at' => '2026-08-04 10:00:00',
        ]);

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.resources.services.update', $service),
                $this->serviceRequirementsPayload($service, [[
                    'scheduling_resource_id' => $resource->id,
                    'is_active' => true,
                    'quantity' => 2,
                    'sort_order' => 0,
                ]]),
            )
            ->assertSessionHasNoErrors();

        $occupancy->refresh();

        $this->assertSame(1, $occupancy->quantity);
        $this->assertSame($resource->id, $occupancy->scheduling_resource_id);
        $this->assertSame($appointment->id, $occupancy->appointment_id);
        $this->assertTrue($occupancy->occupancy_starts_at->equalTo(
            CarbonImmutable::parse('2026-08-04 09:00:00 UTC'),
        ));
        $this->assertTrue($occupancy->occupancy_ends_at->equalTo(
            CarbonImmutable::parse('2026-08-04 10:00:00 UTC'),
        ));
    }

    /**
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(): array
    {
        $host = SchedulingHost::factory()->create([
            'capacity' => 5,
            'timezone' => 'UTC',
        ]);
        $service = BookableService::factory()->create([
            'capacity' => 5,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
        ]);
        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);
        SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->absolute(
                CarbonImmutable::parse('2026-08-04 09:00:00 UTC'),
                CarbonImmutable::parse('2026-08-04 10:00:00 UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 5,
            ]);

        return [$service, $host];
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function resourcePayload(
        array $overrides = [],
        bool $includeKey = true,
    ): array {
        return [
            ...($includeKey ? ['key' => 'configuration_resource'] : []),
            'name' => 'Configuration resource',
            'status' => SchedulingResource::STATUS_ACTIVE,
            'sort_order' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    private function hostResourcesPayload(
        SchedulingHost $host,
        array $resources,
    ): array {
        return [
            'current_version' => $host->fresh()->updated_at->toISOString(),
            'resources' => $resources,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    private function serviceRequirementsPayload(
        BookableService $service,
        array $resources,
    ): array {
        return [
            'current_version' => $service->fresh()->updated_at->toISOString(),
            'resources' => $resources,
        ];
    }
}