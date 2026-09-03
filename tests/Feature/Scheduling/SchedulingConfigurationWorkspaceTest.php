<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingConfigurationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_configuration_routes_require_authentication_and_enabled_module(): void
    {
        $this->get(route('crm.scheduling.configuration.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.index'))
            ->assertNotFound();
    }

    public function test_configuration_workspace_exposes_dedicated_setup_surfaces_and_existing_write_routes(): void
    {
        $user = User::factory()->create();
        $host = SchedulingHost::factory()->create();
        $service = BookableService::factory()->create();
        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.index'))
            ->assertOk()
            ->assertSee('data-scheduling-configuration', false)
            ->assertSee('data-scheduling-setup-area="services"', false)
            ->assertSee('data-scheduling-setup-area="availability"', false)
            ->assertSee('data-scheduling-setup-area="staff"', false)
            ->assertSee(
                route('crm.scheduling.configuration.services.index'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.staff.index'),
                false,
            )
            ->assertDontSee('data-configuration-service-create', false)
            ->assertDontSee('data-configuration-host-create', false);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertOk()
            ->assertSee('data-scheduling-services-workspace', false)
            ->assertSee('data-configuration-service-create', false)
            ->assertSee('data-bookable-service-id="'.$service->id.'"', false)
            ->assertSee(
                route('crm.scheduling.configuration.services.store'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.services.edit', $service),
                false,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.edit', $service))
            ->assertOk()
            ->assertSee('data-scheduling-service-editor="'.$service->id.'"', false)
            ->assertSee(
                'data-configuration-service-update="'.$service->id.'"',
                false,
            )
            ->assertSee(
                'data-service-assignment-form="'.$service->id.'"',
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.services.update', $service),
                false,
            )
            ->assertSee(
                route(
                    'crm.scheduling.configuration.services.hosts.update',
                    $service,
                ),
                false,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.staff.index'))
            ->assertOk()
            ->assertSee('data-scheduling-staff-workspace', false)
            ->assertSee('data-configuration-host-create', false)
            ->assertSee('data-scheduling-host-id="'.$host->id.'"', false)
            ->assertSee(
                route('crm.scheduling.configuration.hosts.store'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.hosts.update', $host),
                false,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.index'))
            ->assertOk()
            ->assertSee('data-scheduling-configuration-link', false)
            ->assertSee(
                route('crm.scheduling.configuration.index'),
                false,
            );
    }

    public function test_first_use_configuration_is_service_first_and_hides_generated_create_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-scheduling-setup-area="services"',
                'data-scheduling-setup-area="availability"',
                'data-scheduling-setup-area="staff"',
            ], false);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.index'))
            ->assertOk()
            ->assertDontSee('name="key"', false)
            ->assertDontSee('name="sort_order"', false);
    }

    public function test_first_use_create_paths_generate_technical_defaults_from_business_inputs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.services.store'), [
                'name' => 'Planning Call',
                'duration_minutes' => 30,
            ])
            ->assertRedirect(route('crm.scheduling.configuration.services.index'))
            ->assertSessionHasNoErrors();

        $service = BookableService::query()->sole();

        $this->assertSame('planning_call', $service->key);
        $this->assertSame(BookableService::STATUS_ACTIVE, $service->status);
        $this->assertSame(BookableService::DURATION_MODE_FIXED, $service->duration_mode);
        $this->assertSame(30, $service->duration_minutes);
        $this->assertSame(15, $service->slot_interval_minutes);
        $this->assertSame(1, $service->capacity);
        $this->assertFalse($service->requires_confirmation);
        $this->assertFalse($service->is_public);
        $this->assertSame(10, $service->sort_order);

        $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.hosts.store'), [
                'name' => 'Taylor Smith',
                'email' => 'taylor@example.test',
            ])
            ->assertRedirect(route('crm.scheduling.configuration.staff.index'))
            ->assertSessionHasNoErrors();

        $host = SchedulingHost::query()->sole();

        $this->assertSame('taylor_smith', $host->key);
        $this->assertSame(SchedulingHost::STATUS_ACTIVE, $host->status);
        $this->assertSame(1, $host->capacity);
        $this->assertSame(10, $host->sort_order);
        $this->assertSame('taylor@example.test', $host->email);
    }

    public function test_manual_hosts_are_created_and_updated_without_caller_owned_internals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.hosts.store'),
                $this->hostPayload([
                    'key' => 'primary_advisor',
                    'name' => 'Primary Advisor',
                    'capacity' => 3,
                    'email' => 'ADVISOR@EXAMPLE.TEST',
                ]),
            )
            ->assertRedirect(route('crm.scheduling.configuration.staff.index'))
            ->assertSessionHasNoErrors();

        $host = SchedulingHost::query()->sole();

        $this->assertSame('primary_advisor', $host->key);
        $this->assertSame('Primary Advisor', $host->name);
        $this->assertSame(3, $host->capacity);
        $this->assertSame('advisor@example.test', $host->email);
        $this->assertSame(SchedulingHost::SOURCE_MANUAL, $host->source);
        $this->assertNull($host->hostable_type);
        $this->assertNull($host->hostable_id);
        $this->assertNull($host->meta);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.hosts.update', $host),
                $this->hostPayload([
                    'current_version' => $host->updated_at->toISOString(),
                    'name' => 'Updated Advisor',
                    'status' => SchedulingHost::STATUS_INACTIVE,
                    'capacity' => 4,
                    'sort_order' => 20,
                ], includeKey: false),
            )
            ->assertRedirect(route('crm.scheduling.configuration.staff.index'))
            ->assertSessionHasNoErrors();

        $host->refresh();

        $this->assertSame('primary_advisor', $host->key);
        $this->assertSame('Updated Advisor', $host->name);
        $this->assertSame(SchedulingHost::STATUS_INACTIVE, $host->status);
        $this->assertSame(4, $host->capacity);
        $this->assertSame(20, $host->sort_order);
    }

    public function test_host_updates_reject_immutable_internal_owned_and_stale_changes(): void
    {
        $user = User::factory()->create();
        $manual = SchedulingHost::factory()->create([
            'key' => 'manual_host',
            'name' => 'Manual Host',
        ]);
        $provider = SchedulingHost::factory()->create([
            'key' => 'provider_host',
            'name' => 'Provider Host',
            'source' => SchedulingHost::SOURCE_PROVIDER,
        ]);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.hosts.update', $manual),
                $this->hostPayload([
                    'current_version' => $manual->updated_at->toISOString(),
                    'key' => 'changed_key',
                ]),
            )
            ->assertSessionHasErrors('configuration');

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.hosts.update', $provider),
                $this->hostPayload([
                    'current_version' => $provider->updated_at->toISOString(),
                    'name' => 'Changed Provider Host',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('configuration');

        $staleVersion = $manual->updated_at->toISOString();
        $manual->forceFill([
            'name' => 'Concurrent Change',
            'updated_at' => $manual->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.hosts.update', $manual),
                $this->hostPayload([
                    'current_version' => $staleVersion,
                    'name' => 'Stale Change',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('configuration');

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.hosts.store'),
                [
                    ...$this->hostPayload(['key' => 'forged_host']),
                    'source' => SchedulingHost::SOURCE_PROVIDER,
                ],
            )
            ->assertSessionHasErrors('configuration');

        $this->assertSame('manual_host', $manual->refresh()->key);
        $this->assertSame('Concurrent Change', $manual->name);
        $this->assertSame('Provider Host', $provider->refresh()->name);
        $this->assertSame(2, SchedulingHost::query()->count());
    }

    public function test_manual_services_are_created_updated_and_keep_provider_identity_server_owned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'planning_call',
                    'name' => 'Planning Call',
                    'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
                    'location_label' => 'Video Room',
                    'location_url' => 'https://example.test/room',
                    'location_instructions' => 'Join from a quiet place.',
                    'requires_confirmation' => true,
                    'is_public' => true,
                ]),
            )
            ->assertRedirect(route('crm.scheduling.configuration.services.index'))
            ->assertSessionHasNoErrors();

        $service = BookableService::query()->sole();

        $this->assertSame('planning_call', $service->key);
        $this->assertSame('Planning Call', $service->name);
        $this->assertTrue($service->requires_confirmation);
        $this->assertTrue($service->is_public);
        $this->assertSame('manual', $service->source);
        $this->assertNull($service->provider);
        $this->assertNull($service->external_id);
        $this->assertNull($service->external_url);
        $this->assertNull($service->meta);
        $this->assertEquals([
            'label' => 'Video Room',
            'url' => 'https://example.test/room',
            'instructions' => 'Join from a quiet place.',
        ], $service->location_details);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.services.update', $service),
                $this->servicePayload([
                    'current_version' => $service->updated_at->toISOString(),
                    'name' => 'Updated Planning Call',
                    'status' => BookableService::STATUS_INACTIVE,
                    'is_public' => true,
                    'duration_minutes' => 45,
                    'location_type' => null,
                    'location_label' => '',
                    'location_instructions' => '',
                    'location_url' => '',
                ], includeKey: false),
            )
            ->assertRedirect(route('crm.scheduling.configuration.services.edit', $service))
            ->assertSessionHasNoErrors();

        $service->refresh();

        $this->assertSame('planning_call', $service->key);
        $this->assertSame('Updated Planning Call', $service->name);
        $this->assertSame(BookableService::STATUS_INACTIVE, $service->status);
        $this->assertFalse($service->is_public);
        $this->assertSame(45, $service->duration_minutes);
        $this->assertNull($service->location_details);
    }


    public function test_manual_service_duration_authoring_supports_range_policy_and_clears_bounds_when_returned_to_fixed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'boarding_stay',
                    'name' => 'Boarding Stay',
                    'duration_mode' => BookableService::DURATION_MODE_RANGE,
                    'duration_minutes' => 2880,
                    'minimum_duration_minutes' => 1440,
                    'maximum_duration_minutes' => 10080,
                ]),
            )
            ->assertRedirect(route('crm.scheduling.configuration.services.index'))
            ->assertSessionHasNoErrors();

        $service = BookableService::query()->sole();

        $this->assertSame(BookableService::DURATION_MODE_RANGE, $service->duration_mode);
        $this->assertSame(2880, $service->duration_minutes);
        $this->assertSame(1440, $service->minimum_duration_minutes);
        $this->assertSame(10080, $service->maximum_duration_minutes);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.services.update', $service),
                $this->servicePayload([
                    'current_version' => $service->updated_at->toISOString(),
                    'duration_mode' => BookableService::DURATION_MODE_FIXED,
                    'duration_minutes' => 90,
                ], includeKey: false),
            )
            ->assertRedirect(route('crm.scheduling.configuration.services.edit', $service))
            ->assertSessionHasNoErrors();

        $service->refresh();

        $this->assertSame(BookableService::DURATION_MODE_FIXED, $service->duration_mode);
        $this->assertSame(90, $service->duration_minutes);
        $this->assertNull($service->minimum_duration_minutes);
        $this->assertNull($service->maximum_duration_minutes);
    }

    public function test_service_location_authoring_is_closed_and_fixed_addresses_are_normalized(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'office_visit',
                    'name' => 'Office Visit',
                    'location_type' => BookableService::LOCATION_TYPE_FIXED,
                    'location_label' => 'Main office',
                    'location_instructions' => 'Use the north entrance.',
                    'location_address_line_1' => '  50   Office Plaza ',
                    'location_city' => ' Denver ',
                    'location_region' => ' CO ',
                    'location_postal_code' => ' 80205 ',
                    'location_country' => 'us',
                ]),
            )
            ->assertSessionHasNoErrors();

        $fixed = BookableService::query()->where('key', 'office_visit')->sole();

        $this->assertSame(BookableService::LOCATION_TYPE_FIXED, $fixed->location_type);
        $this->assertSame('Main office', data_get($fixed->location_details, 'label'));
        $this->assertSame('Use the north entrance.', data_get($fixed->location_details, 'instructions'));
        $this->assertSame('50 Office Plaza', data_get($fixed->location_details, 'address.address_line_1'));
        $this->assertSame('US', data_get($fixed->location_details, 'address.country'));
        $this->assertSame(
            '50 Office Plaza, Denver, CO 80205, US',
            data_get($fixed->location_details, 'address.formatted_address'),
        );

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'mobile_visit',
                    'name' => 'Mobile Visit',
                    'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
                    'location_label' => 'Customer address',
                    'location_instructions' => 'Meet the customer at the submitted address.',
                ]),
            )
            ->assertSessionHasNoErrors();

        $customerSite = BookableService::query()->where('key', 'mobile_visit')->sole();

        $this->assertEquals([
            'label' => 'Customer address',
            'instructions' => 'Meet the customer at the submitted address.',
        ], $customerSite->location_details);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'bad_location_type',
                    'location_type' => 'video',
                ]),
            )
            ->assertSessionHasErrors('location_type');

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                $this->servicePayload([
                    'key' => 'bad_customer_site',
                    'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
                    'location_address_line_1' => '123 Should Not Persist',
                ]),
            )
            ->assertSessionHasErrors('location_address_line_1');
    }

    public function test_service_updates_reject_provider_owned_immutable_internal_and_stale_changes(): void
    {
        $user = User::factory()->create();
        $manual = BookableService::factory()->create([
            'key' => 'manual_service',
            'name' => 'Manual Service',
        ]);
        $provider = BookableService::factory()->create([
            'key' => 'provider_service',
            'name' => 'Provider Service',
            'source' => 'provider',
            'provider' => 'calendar_provider',
            'external_id' => 'remote-1',
        ]);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.services.update', $manual),
                $this->servicePayload([
                    'current_version' => $manual->updated_at->toISOString(),
                    'key' => 'changed_service',
                ]),
            )
            ->assertSessionHasErrors('configuration');

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.services.update', $provider),
                $this->servicePayload([
                    'current_version' => $provider->updated_at->toISOString(),
                    'name' => 'Changed Provider Service',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('configuration');

        $staleVersion = $manual->updated_at->toISOString();
        $manual->forceFill([
            'name' => 'Concurrent Service Change',
            'updated_at' => $manual->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.services.update', $manual),
                $this->servicePayload([
                    'current_version' => $staleVersion,
                    'name' => 'Stale Service Change',
                ], includeKey: false),
            )
            ->assertSessionHasErrors('configuration');

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.services.store'),
                [
                    ...$this->servicePayload(['key' => 'forged_service']),
                    'provider' => 'forged',
                ],
            )
            ->assertSessionHasErrors('configuration');

        $this->assertSame('manual_service', $manual->refresh()->key);
        $this->assertSame('Concurrent Service Change', $manual->name);
        $this->assertSame('Provider Service', $provider->refresh()->name);
        $this->assertSame(2, BookableService::query()->count());
    }

    public function test_assignment_sync_preserves_inactive_rows_and_changes_operational_availability(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 5,
        ]);
        $primaryHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'capacity' => 5,
        ]);
        $omittedHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        $inactiveHost = SchedulingHost::factory()->inactive()->create();
        $omittedAssignment = BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $omittedHost->id,
            'is_active' => true,
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');

        SchedulingAvailabilityWindow::factory()
            ->absolute($startsAt, $startsAt->addHour())
            ->forServiceAndHost($service, $primaryHost)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 5,
            ]);

        $this->actingAs($user)
            ->put(
                route(
                    'crm.scheduling.configuration.services.hosts.update',
                    $service,
                ),
                [
                    'current_version' => $service->updated_at->toISOString(),
                    'assignments' => [[
                        'scheduling_host_id' => $primaryHost->id,
                        'is_active' => true,
                        'capacity_override' => 2,
                        'sort_order' => 10,
                    ]],
                ],
            )
            ->assertRedirect(route('crm.scheduling.configuration.services.edit', $service))
            ->assertSessionHasNoErrors();

        $primaryAssignment = BookableServiceHost::query()
            ->where('bookable_service_id', $service->id)
            ->where('scheduling_host_id', $primaryHost->id)
            ->sole();

        $this->assertTrue($primaryAssignment->is_active);
        $this->assertSame(2, $primaryAssignment->capacity_override);
        $this->assertSame(10, $primaryAssignment->sort_order);
        $this->assertFalse($omittedAssignment->refresh()->is_active);

        $slots = app(SchedulingReadService::class)->availabilityForDate(
            service: $service->refresh(),
            date: CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
            host: $primaryHost,
        );

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots[0]->capacity);
        $this->assertSame(2, $slots[0]->remainingCapacity);

        $service->refresh();

        $this->actingAs($user)
            ->put(
                route(
                    'crm.scheduling.configuration.services.hosts.update',
                    $service,
                ),
                [
                    'current_version' => $service->updated_at->toISOString(),
                    'assignments' => [[
                        'scheduling_host_id' => $primaryHost->id,
                        'is_active' => false,
                        'capacity_override' => 2,
                        'sort_order' => 10,
                    ]],
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertFalse($primaryAssignment->refresh()->is_active);
        $this->assertSame(2, BookableServiceHost::query()
            ->where('bookable_service_id', $service->id)
            ->count());
        $this->assertEmpty(app(SchedulingReadService::class)->availabilityForDate(
            service: $service->refresh(),
            date: CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
            host: $primaryHost,
        ));

        $service->refresh();

        $this->actingAs($user)
            ->put(
                route(
                    'crm.scheduling.configuration.services.hosts.update',
                    $service,
                ),
                [
                    'current_version' => $service->updated_at->toISOString(),
                    'assignments' => [[
                        'scheduling_host_id' => $inactiveHost->id,
                        'is_active' => true,
                        'capacity_override' => null,
                        'sort_order' => 0,
                    ]],
                ],
            )
            ->assertSessionHasErrors('configuration');

        $this->assertDatabaseMissing('bookable_service_hosts', [
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $inactiveHost->id,
        ]);
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
    private function hostPayload(
        array $overrides = [],
        bool $includeKey = true,
    ): array {
        return [
            ...($includeKey ? ['key' => 'configuration_host'] : []),
            'name' => 'Configuration Host',
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'capacity' => 1,
            'email' => null,
            'phone' => null,
            'sort_order' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function servicePayload(
        array $overrides = [],
        bool $includeKey = true,
    ): array {
        return [
            ...($includeKey ? ['key' => 'configuration_service'] : []),
            'name' => 'Configuration Service',
            'description' => null,
            'status' => BookableService::STATUS_ACTIVE,
            'duration_mode' => BookableService::DURATION_MODE_FIXED,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 60,
            'cancellation_notice_minutes' => 0,
            'reschedule_notice_minutes' => 0,
            'timezone' => 'UTC',
            'location_type' => null,
            'location_label' => null,
            'location_instructions' => null,
            'location_url' => null,
            'location_address_line_1' => null,
            'location_address_line_2' => null,
            'location_city' => null,
            'location_region' => null,
            'location_postal_code' => null,
            'location_country' => null,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => false,
            'sort_order' => 0,
            ...$overrides,
        ];
    }
}