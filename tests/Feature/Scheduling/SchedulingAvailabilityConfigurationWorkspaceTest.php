<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingAvailabilityConfigurationWorkspaceTest extends TestCase
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

    public function test_availability_routes_require_authentication_and_enabled_module(): void
    {
        $this->get(route('crm.scheduling.configuration.availability.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.configuration.availability.index'))
            ->assertNotFound();
    }

    public function test_workspace_exposes_business_hours_special_dates_preview_and_advanced_rules(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        $host = SchedulingHost::factory()->create();
        $window = SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->create();

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);
        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-10 12:00:00 UTC'),
                CarbonImmutable::parse('2026-08-10 13:00:00 UTC'),
            )
            ->serviceWide($service)
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
                'is_available' => false,
            ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->id,
            ]))
            ->assertOk()
            ->assertSee('data-scheduling-availability-configuration', false)
            ->assertSee('data-availability-service-selector', false)
            ->assertSee('data-availability-regular-hours', false)
            ->assertSee('data-availability-special-hours', false)
            ->assertSee('data-availability-time-off', false)
            ->assertSee('data-availability-preview', false)
            ->assertSee('data-availability-advanced', false)
            ->assertSee('data-availability-window-id="'.$window->id.'"', false)
            ->assertSee(
                route('crm.scheduling.configuration.availability.regular-hours', $service),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.availability.special-hours', $service),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.availability.time-off', $service),
                false,
            )
            ->assertSee(
                route(
                    'crm.scheduling.configuration.availability.date-changes.destroy',
                    [$service, '2026-08-10'],
                ),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.availability.store'),
                false,
            )
            ->assertSee(
                route('crm.scheduling.configuration.availability.update', $window),
                false,
            )
            ->assertViewHas('previewRequiresHost', true)
            ->assertViewHas(
                'previewHost',
                fn ($value): bool => $value instanceof SchedulingHost
                    && $value->id === $host->id,
            );

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.index'))
            ->assertOk()
            ->assertSee('data-scheduling-availability-configuration-link', false)
            ->assertSee(
                route('crm.scheduling.configuration.availability.index'),
                false,
            );
    }

    public function test_regular_hours_business_flow_syncs_simple_service_schedule_without_overwriting_advanced_rows(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        $simple = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '17:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
            ]);
        $advanced = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(2, '10:00:00', '14:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => 2,
            ]);

        $payload = $this->regularHoursPayload([
            1 => [
                ['start' => '08:00', 'end' => '12:00'],
                ['start' => '13:00', 'end' => '17:00'],
            ],
            3 => [
                ['start' => '10:00', 'end' => '15:00'],
            ],
        ]);

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.regular-hours', $service),
                $payload,
            )
            ->assertRedirect(route(
                'crm.scheduling.configuration.availability.index',
                ['service_id' => $service->id],
            ))
            ->assertSessionHasNoErrors();

        $simpleRows = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->id)
            ->whereNull('scheduling_host_id')
            ->where('is_available', true)
            ->whereNull('capacity')
            ->where('window_type', SchedulingAvailabilityWindowType::Weekly->value)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        $this->assertCount(3, $simpleRows);
        $this->assertSame($simple->id, $simpleRows[0]->id);
        $this->assertSame(1, $simpleRows[0]->weekday);
        $this->assertSame('08:00:00', $simpleRows[0]->start_time);
        $this->assertSame('12:00:00', $simpleRows[0]->end_time);
        $this->assertSame(1, $simpleRows[1]->weekday);
        $this->assertSame('13:00:00', $simpleRows[1]->start_time);
        $this->assertSame('17:00:00', $simpleRows[1]->end_time);
        $this->assertSame(3, $simpleRows[2]->weekday);
        $this->assertSame('10:00:00', $simpleRows[2]->start_time);
        $this->assertSame('15:00:00', $simpleRows[2]->end_time);

        $advanced->refresh();
        $this->assertFalse($advanced->trashed());
        $this->assertSame(2, $advanced->capacity);
        $this->assertSame('10:00:00', $advanced->start_time);
        $this->assertSame(4, SchedulingAvailabilityWindow::withTrashed()->count());

        $ids = $simpleRows->pluck('id')->all();

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.regular-hours', $service),
                [
                    ...$payload,
                    'source' => SchedulingAvailabilityWindow::SOURCE_PROVIDER,
                ],
            )
            ->assertSessionHasErrors('availability');

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.regular-hours', $service),
                $payload,
            )
            ->assertSessionHasNoErrors();

        $this->assertEquals(
            $ids,
            SchedulingAvailabilityWindow::query()
                ->where('bookable_service_id', $service->id)
                ->whereNull('scheduling_host_id')
                ->where('is_available', true)
                ->whereNull('capacity')
                ->where('window_type', SchedulingAvailabilityWindowType::Weekly->value)
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(4, SchedulingAvailabilityWindow::withTrashed()->count());
    }

    public function test_special_hours_replace_the_regular_schedule_for_one_date(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '17:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
            ]);

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.special-hours', $service),
                [
                    'date' => '2026-08-10',
                    'ranges' => [
                        ['start' => '10:00', 'end' => '12:00'],
                        ['start' => '13:00', 'end' => '15:00'],
                    ],
                ],
            )
            ->assertSessionHasNoErrors();

        $starts = array_map(
            static fn ($slot): string => $slot->startsAt->format('H:i'),
            app(SchedulingReadService::class)->availabilityForDate(
                service: $service,
                date: CarbonImmutable::parse('2026-08-10 00:00:00 UTC'),
            ),
        );

        $this->assertEquals(['10:00', '11:00', '13:00', '14:00'], $starts);

        $dateRows = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->id)
            ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(5, $dateRows);
        $this->assertFalse($dateRows[0]->is_available);
        $this->assertTrue($dateRows[1]->is_available);
        $this->assertFalse($dateRows[2]->is_available);
        $this->assertTrue($dateRows[3]->is_available);
        $this->assertFalse($dateRows[4]->is_available);

        $existingIds = $dateRows->pluck('id')->all();

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.special-hours', $service),
                [
                    'date' => '2026-08-10',
                    'ranges' => [
                        ['start' => '11:00', 'end' => '14:00'],
                    ],
                ],
            )
            ->assertSessionHasNoErrors();

        $activeDateRows = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->id)
            ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(3, $activeDateRows);
        $this->assertEquals(
            array_slice($existingIds, 0, 3),
            $activeDateRows->pluck('id')->all(),
        );
        $this->assertSame(
            5,
            SchedulingAvailabilityWindow::withTrashed()
                ->where('bookable_service_id', $service->id)
                ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
                ->count(),
        );

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.special-hours', $service),
                [
                    'date' => '2026-08-10',
                    'ranges' => [
                        ['start' => '10:00', 'end' => '12:00'],
                        ['start' => '13:00', 'end' => '15:00'],
                    ],
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            5,
            SchedulingAvailabilityWindow::withTrashed()
                ->where('bookable_service_id', $service->id)
                ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
                ->count(),
        );
        $this->assertSame(
            5,
            SchedulingAvailabilityWindow::query()
                ->where('bookable_service_id', $service->id)
                ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
                ->count(),
        );
    }

    public function test_special_date_summary_reflects_additional_time_off(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);

        $this->actingAs($user)
            ->put(
                route('crm.scheduling.configuration.availability.special-hours', $service),
                [
                    'date' => '2026-08-10',
                    'ranges' => [
                        ['start' => '10:00', 'end' => '12:00'],
                        ['start' => '13:00', 'end' => '15:00'],
                    ],
                ],
            )
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.time-off', $service),
                [
                    'date' => '2026-08-10',
                    'all_day' => false,
                    'start_time' => '11:00',
                    'end_time' => '11:30',
                ],
            )
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.availability.index', [
                'service_id' => $service->id,
            ]))
            ->assertOk()
            ->assertViewHas('dateChanges', function (array $changes): bool {
                $this->assertCount(1, $changes);
                $this->assertSame('2026-08-10', $changes[0]['date']);
                $this->assertSame('special_hours', $changes[0]['type']);
                $this->assertEquals([
                    ['start' => '10:00', 'end' => '11:00'],
                    ['start' => '11:30', 'end' => '12:00'],
                    ['start' => '13:00', 'end' => '15:00'],
                ], $changes[0]['ranges']);

                return true;
            });
    }

    public function test_time_off_can_block_part_or_all_of_a_regular_day(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '17:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
            ]);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.time-off', $service),
                [
                    'date' => '2026-08-10',
                    'all_day' => false,
                    'start_time' => '12:00',
                    'end_time' => '13:00',
                ],
            )
            ->assertSessionHasNoErrors();

        $starts = array_map(
            static fn ($slot): string => $slot->startsAt->format('H:i'),
            app(SchedulingReadService::class)->availabilityForDate(
                service: $service,
                date: CarbonImmutable::parse('2026-08-10 00:00:00 UTC'),
            ),
        );

        $this->assertEquals(
            ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'],
            $starts,
        );

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.time-off', $service),
                [
                    'date' => '2026-08-17',
                    'all_day' => true,
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertEmpty(app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: CarbonImmutable::parse('2026-08-17 00:00:00 UTC'),
        ));
    }

    public function test_clearing_one_off_change_restores_regular_hours_without_deleting_history(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '17:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => null,
            ]);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.time-off', $service),
                [
                    'date' => '2026-08-10',
                    'all_day' => true,
                ],
            )
            ->assertSessionHasNoErrors();

        $dateChange = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->id)
            ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
            ->sole();

        $this->assertEmpty(app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: CarbonImmutable::parse('2026-08-10 00:00:00 UTC'),
        ));

        $this->actingAs($user)
            ->delete(route(
                'crm.scheduling.configuration.availability.date-changes.destroy',
                [$service, '2026-08-10'],
            ))
            ->assertRedirect(route(
                'crm.scheduling.configuration.availability.index',
                ['service_id' => $service->id],
            ))
            ->assertSessionHasNoErrors();

        $starts = array_map(
            static fn ($slot): string => $slot->startsAt->format('H:i'),
            app(SchedulingReadService::class)->availabilityForDate(
                service: $service,
                date: CarbonImmutable::parse('2026-08-10 00:00:00 UTC'),
            ),
        );

        $this->assertEquals(
            ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'],
            $starts,
        );
        $this->assertSame(
            1,
            SchedulingAvailabilityWindow::withTrashed()
                ->where('id', $dateChange->id)
                ->count(),
        );
        $this->assertTrue($dateChange->refresh()->trashed());

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.time-off', $service),
                [
                    'date' => '2026-08-10',
                    'all_day' => true,
                ],
            )
            ->assertSessionHasNoErrors();

        $restored = SchedulingAvailabilityWindow::query()
            ->where('bookable_service_id', $service->id)
            ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
            ->sole();

        $this->assertSame($dateChange->id, $restored->id);
        $this->assertSame(
            1,
            SchedulingAvailabilityWindow::withTrashed()
                ->where('bookable_service_id', $service->id)
                ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
                ->count(),
        );
    }

    public function test_manual_weekly_and_absolute_rules_persist_closed_server_owned_shapes(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create();
        $host = SchedulingHost::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->weeklyPayload([
                    'bookable_service_id' => $service->id,
                    'weekday' => 1,
                    'start_time' => '08:30',
                    'end_time' => '12:15',
                    'capacity' => 3,
                ]),
            )
            ->assertRedirect(route('crm.scheduling.configuration.availability.index'))
            ->assertSessionHasNoErrors();

        $weekly = SchedulingAvailabilityWindow::query()->sole();

        $this->assertSame($service->id, $weekly->bookable_service_id);
        $this->assertNull($weekly->scheduling_host_id);
        $this->assertSame(SchedulingAvailabilityWindowType::Weekly, $weekly->window_type);
        $this->assertSame(1, $weekly->weekday);
        $this->assertSame('08:30:00', $weekly->start_time);
        $this->assertSame('12:15:00', $weekly->end_time);
        $this->assertNull($weekly->starts_at);
        $this->assertNull($weekly->ends_at);
        $this->assertSame(3, $weekly->capacity);
        $this->assertSame(SchedulingAvailabilityWindow::SOURCE_MANUAL, $weekly->source);
        $this->assertNull($weekly->meta);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->absolutePayload([
                    'scope' => 'host',
                    'bookable_service_id' => null,
                    'scheduling_host_id' => $host->id,
                    'timezone' => 'America/Chicago',
                    'local_starts_at' => '2026-08-12T09:00',
                    'local_ends_at' => '2026-08-12T11:00',
                    'is_available' => false,
                ]),
            )
            ->assertSessionHasNoErrors();

        $absolute = SchedulingAvailabilityWindow::query()
            ->where('id', '!=', $weekly->id)
            ->sole();

        $this->assertNull($absolute->bookable_service_id);
        $this->assertSame($host->id, $absolute->scheduling_host_id);
        $this->assertSame(SchedulingAvailabilityWindowType::Absolute, $absolute->window_type);
        $this->assertSame('America/Chicago', $absolute->timezone);
        $this->assertSame('2026-08-12 14:00:00', $absolute->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-12 16:00:00', $absolute->ends_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($absolute->weekday);
        $this->assertNull($absolute->start_time);
        $this->assertNull($absolute->end_time);
        $this->assertFalse($absolute->is_available);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                [
                    ...$this->weeklyPayload([
                        'bookable_service_id' => $service->id,
                    ]),
                    'source' => SchedulingAvailabilityWindow::SOURCE_PROVIDER,
                ],
            )
            ->assertSessionHasErrors('availability');

        $this->assertSame(2, SchedulingAvailabilityWindow::query()->count());
    }

    public function test_combined_rules_require_a_durable_assignment_and_may_use_inactive_assignments(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create();
        $host = SchedulingHost::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->weeklyPayload([
                    'scope' => 'service_host',
                    'bookable_service_id' => $service->id,
                    'scheduling_host_id' => $host->id,
                ]),
            )
            ->assertSessionHasErrors('availability');

        BookableServiceHost::factory()->inactive()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->weeklyPayload([
                    'scope' => 'service_host',
                    'bookable_service_id' => $service->id,
                    'scheduling_host_id' => $host->id,
                ]),
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('scheduling_availability_windows', [
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
        ]);
    }

    public function test_updates_enforce_manual_ownership_versions_and_shape_replacement(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create();
        $manual = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '11:00:00')
            ->create();
        $provider = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->create([
                'source' => SchedulingAvailabilityWindow::SOURCE_PROVIDER,
            ]);

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.availability.update', $provider),
                [
                    'current_version' => $provider->updated_at->toISOString(),
                    ...$this->weeklyPayload([
                        'bookable_service_id' => $service->id,
                    ]),
                ],
            )
            ->assertSessionHasErrors('availability');

        $staleVersion = $manual->updated_at->toISOString();
        $manual->forceFill([
            'capacity' => 4,
            'updated_at' => $manual->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.availability.update', $manual),
                [
                    'current_version' => $staleVersion,
                    ...$this->weeklyPayload([
                        'bookable_service_id' => $service->id,
                        'capacity' => 2,
                    ]),
                ],
            )
            ->assertSessionHasErrors('availability');

        $manual->refresh();

        $this->actingAs($user)
            ->patch(
                route('crm.scheduling.configuration.availability.update', $manual),
                [
                    'current_version' => $manual->updated_at->toISOString(),
                    ...$this->absolutePayload([
                        'bookable_service_id' => $service->id,
                        'timezone' => 'UTC',
                        'local_starts_at' => '2026-08-10T13:00',
                        'local_ends_at' => '2026-08-10T15:00',
                        'capacity' => 2,
                    ]),
                ],
            )
            ->assertSessionHasNoErrors();

        $manual->refresh();

        $this->assertSame(SchedulingAvailabilityWindowType::Absolute, $manual->window_type);
        $this->assertNull($manual->weekday);
        $this->assertNull($manual->start_time);
        $this->assertNull($manual->end_time);
        $this->assertSame('2026-08-10 13:00:00', $manual->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $manual->capacity);
        $this->assertSame(1, $provider->refresh()->capacity);
    }

    public function test_absolute_local_times_reject_nonexistent_and_ambiguous_clock_values(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->absolutePayload([
                    'bookable_service_id' => $service->id,
                    'timezone' => 'America/Chicago',
                    'local_starts_at' => '2026-03-08T02:30',
                    'local_ends_at' => '2026-03-08T04:00',
                ]),
            )
            ->assertSessionHasErrors('availability');

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.store'),
                $this->absolutePayload([
                    'bookable_service_id' => $service->id,
                    'timezone' => 'America/Chicago',
                    'local_starts_at' => '2026-11-01T01:30',
                    'local_ends_at' => '2026-11-01T03:00',
                ]),
            )
            ->assertSessionHasErrors('availability');

        $this->assertSame(0, SchedulingAvailabilityWindow::withTrashed()->count());
    }

    public function test_archive_and_restore_are_non_destructive_and_change_resolved_availability(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
        ]);
        $window = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '11:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => 2,
            ]);
        $date = CarbonImmutable::parse('2026-08-10 00:00:00 UTC');

        $this->assertCount(2, app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: $date,
        ));

        $staleArchiveVersion = $window->updated_at->toISOString();
        $window->forceFill([
            'updated_at' => $window->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->delete(
                route('crm.scheduling.configuration.availability.archive', $window),
                ['current_version' => $staleArchiveVersion],
            )
            ->assertSessionHasErrors('availability');

        $this->assertFalse($window->refresh()->trashed());

        $this->actingAs($user)
            ->delete(
                route('crm.scheduling.configuration.availability.archive', $window),
                ['current_version' => $window->updated_at->toISOString()],
            )
            ->assertSessionHasNoErrors();

        $window = SchedulingAvailabilityWindow::withTrashed()->findOrFail($window->id);

        $this->assertTrue($window->trashed());
        $this->assertSame(1, SchedulingAvailabilityWindow::withTrashed()->count());
        $this->assertEmpty(app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: $date,
        ));

        $staleRestoreVersion = $window->updated_at->toISOString();
        $window->forceFill([
            'updated_at' => $window->updated_at->addMinute(),
        ])->saveQuietly();

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.restore', $window),
                ['current_version' => $staleRestoreVersion],
            )
            ->assertSessionHasErrors('availability');

        $window = SchedulingAvailabilityWindow::withTrashed()->findOrFail($window->id);
        $this->assertTrue($window->trashed());

        $this->actingAs($user)
            ->post(
                route('crm.scheduling.configuration.availability.restore', $window),
                ['current_version' => $window->updated_at->toISOString()],
            )
            ->assertSessionHasNoErrors();

        $window->refresh();

        $this->assertFalse($window->trashed());
        $this->assertSame(1, SchedulingAvailabilityWindow::withTrashed()->count());
        $this->assertCount(2, app(SchedulingReadService::class)->availabilityForDate(
            service: $service,
            date: $date,
        ));
    }

    public function test_preview_uses_live_engine_capacity_blackouts_and_rule_provenance(): void
    {
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 5,
        ]);
        $available = SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->weekly(1, '09:00:00', '12:00:00')
            ->create([
                'timezone' => 'UTC',
                'capacity' => 2,
            ]);
        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-08-10 10:00:00 UTC'),
                CarbonImmutable::parse('2026-08-10 11:00:00 UTC'),
            )
            ->unavailable()
            ->create(['timezone' => 'UTC']);

        $response = $this->actingAs($user)->get(route(
            'crm.scheduling.configuration.availability.index',
            [
                'service_id' => $service->id,
                'preview_date' => '2026-08-10',
            ],
        ));

        $response
            ->assertOk()
            ->assertSee('data-availability-preview', false)
            ->assertSee('id="availability-preview"', false)
            ->assertSee('#availability-preview', false)
            ->assertSee('data-preview-range-first="2026-08-10T09:00:00.000000Z"', false)
            ->assertSee('data-preview-range-first="2026-08-10T11:00:00.000000Z"', false)
            ->assertDontSee('data-preview-range-first="2026-08-10T10:00:00.000000Z"', false)
            ->assertSee('data-availability-preview-ready', false)
            ->assertViewHas('previewSlots', fn (array $slots): bool =>
                count($slots) === 2
                && $slots[0]->capacity === 2
                && $slots[1]->capacity === 2
            )
            ->assertViewHas('previewStartRanges', fn (array $ranges): bool =>
                count($ranges) === 2
                && $ranges[0]['slot_count'] === 1
                && $ranges[1]['slot_count'] === 1
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

    /**
     * @param array<int, array<int, array{start: string, end: string}>> $rangesByWeekday
     * @return array<string, mixed>
     */
    private function regularHoursPayload(array $rangesByWeekday): array
    {
        $days = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $days[$weekday] = [
                'weekday' => $weekday,
                'ranges' => $rangesByWeekday[$weekday] ?? [],
            ];
        }

        return ['regular_hours' => $days];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function weeklyPayload(array $overrides = []): array
    {
        return [
            'scope' => 'service',
            'bookable_service_id' => null,
            'scheduling_host_id' => null,
            'window_type' => SchedulingAvailabilityWindowType::Weekly->value,
            'timezone' => 'UTC',
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'local_starts_at' => null,
            'local_ends_at' => null,
            'capacity' => null,
            'is_available' => true,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function absolutePayload(array $overrides = []): array
    {
        return [
            'scope' => 'service',
            'bookable_service_id' => null,
            'scheduling_host_id' => null,
            'window_type' => SchedulingAvailabilityWindowType::Absolute->value,
            'timezone' => 'UTC',
            'weekday' => null,
            'start_time' => null,
            'end_time' => null,
            'local_starts_at' => '2026-08-10T09:00',
            'local_ends_at' => '2026-08-10T11:00',
            'capacity' => null,
            'is_available' => true,
            ...$overrides,
        ];
    }
}