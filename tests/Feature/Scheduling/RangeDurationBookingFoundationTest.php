<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Actions\CreatePublicBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\ReleaseBookingHoldAction;
use App\Modules\Scheduling\Actions\RescheduleAppointmentToSlotAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Models\SchedulingResourceOccupancy;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class RangeDurationBookingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_range_duration_schema_is_first_class_and_existing_services_default_to_fixed(): void
    {
        $this->assertTrue(Schema::hasColumns('bookable_services', [
            'duration_mode',
            'duration_minutes',
            'minimum_duration_minutes',
            'maximum_duration_minutes',
        ]));

        $fixed = BookableService::factory()->create();

        $this->assertSame(BookableService::DURATION_MODE_FIXED, $fixed->duration_mode);
        $this->assertTrue($fixed->usesFixedDuration());
        $this->assertFalse($fixed->usesRangeDuration());
        $this->assertSame(60, $fixed->defaultDurationMinutes());
        $this->assertSame(60, $fixed->minimumDurationMinutes());
        $this->assertSame(60, $fixed->maximumDurationMinutes());

        $range = BookableService::factory()
            ->rangeDuration(
                defaultMinutes: 2880,
                minimumMinutes: 1440,
                maximumMinutes: 10080,
            )
            ->create();

        $this->assertSame(BookableService::DURATION_MODE_RANGE, $range->duration_mode);
        $this->assertTrue($range->usesRangeDuration());
        $this->assertSame(2880, $range->defaultDurationMinutes());
        $this->assertSame(1440, $range->minimumDurationMinutes());
        $this->assertSame(10080, $range->maximumDurationMinutes());
        $this->assertTrue($range->allowsDurationMinutes(4320));
        $this->assertFalse($range->allowsDurationMinutes(720));
    }

    public function test_range_availability_accepts_an_explicit_multi_day_candidate_duration(): void
    {
        $service = $this->rangeService();
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-12 08:00:00',
            endsAt: '2026-08-12 18:00:00',
        );
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-15 08:00:00',
            endsAt: '2026-08-15 18:00:00',
        );

        $startsAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC');
        $endsAt = CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC');
        $slots = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                candidateDurationMinutes: 4320,
            ),
        );

        $this->assertCount(1, $slots);
        $this->assertTrue($slots[0]->startsAt->equalTo($startsAt));
        $this->assertTrue($slots[0]->endsAt->equalTo($endsAt));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Availability candidate duration [720] is outside the service duration policy.',
        );

        new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $startsAt->addHours(12),
            displayTimezone: 'UTC',
            evaluatedAt: CarbonImmutable::now('UTC'),
            candidateDurationMinutes: 720,
        );
    }

    public function test_direct_range_creation_requires_and_persists_an_explicit_end_time(): void
    {
        $service = $this->rangeService();
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-11 08:00:00',
            endsAt: '2026-08-20 18:00:00',
        );
        $startsAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC');
        $endsAt = CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC');
        $contact = Contact::factory()->create();
        $action = app(CreateAppointmentAction::class);

        try {
            $action->handle(new AppointmentCreationData(
                service: $service,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(
                    contact: $contact,
                    source: 'crm',
                ),
                idempotencyKey: 'range-direct-missing-end',
            ));

            $this->fail('Range-duration direct creation should require an explicit end time.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Range-duration services require an explicit end time.',
                $exception->getMessage(),
            );
        }

        $data = new AppointmentCreationData(
            service: $service,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'crm',
            ),
            idempotencyKey: 'range-direct-explicit-end',
            endsAt: $endsAt,
        );

        $appointment = $action->handle($data);
        $replayed = $action->handle($data);

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertTrue($appointment->starts_at?->equalTo($startsAt));
        $this->assertTrue($appointment->ends_at?->equalTo($endsAt));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The appointment idempotency key was already used for another creation request.',
        );

        $action->handle(new AppointmentCreationData(
            service: $service,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'crm',
            ),
            idempotencyKey: 'range-direct-explicit-end',
            endsAt: $endsAt->addDay(),
        ));
    }

    public function test_range_hold_consumes_resource_capacity_for_the_entire_stay(): void
    {
        [$service, $host] = $this->hostedRangeService();
        $this->absoluteAvailability(
            service: $service,
            host: $host,
            startsAt: '2026-08-11 08:00:00',
            endsAt: '2026-08-20 18:00:00',
        );
        $resource = SchedulingResource::query()->create([
            'key' => 'boarding-space',
            'name' => 'Boarding Space',
            'status' => SchedulingResource::STATUS_ACTIVE,
        ]);
        SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 1,
            'is_active' => true,
        ]);
        BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 1,
            'is_active' => true,
        ]);

        $hold = app(CreatePublicBookingHoldAction::class)->handle(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC'),
            endsAt: CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC'),
            idempotencyKey: 'range-public-hold',
        );

        $this->assertTrue($hold->starts_at?->equalTo(
            CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC'),
        ));
        $this->assertTrue($hold->ends_at?->equalTo(
            CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC'),
        ));

        $occupancy = SchedulingResourceOccupancy::query()->sole();

        $this->assertSame($hold->id, $occupancy->booking_hold_id);
        $this->assertTrue($occupancy->occupancy_starts_at?->equalTo($hold->starts_at));
        $this->assertTrue($occupancy->occupancy_ends_at?->equalTo($hold->ends_at));

        $overlapping = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-08-13 10:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-08-16 10:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                candidateDurationMinutes: 4320,
            ),
        );

        $this->assertSame([], $overlapping);

        app(ReleaseBookingHoldAction::class)->handle($hold->hold_id);

        $availableAfterRelease = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-08-13 10:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-08-16 10:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                candidateDurationMinutes: 4320,
            ),
        );

        $this->assertCount(1, $availableAfterRelease);
    }

    public function test_range_rescheduling_preserves_the_original_interval_duration(): void
    {
        $service = $this->rangeService();
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-11 08:00:00',
            endsAt: '2026-08-25 18:00:00',
        );
        $contact = Contact::factory()->create();
        $originalStartsAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC');
        $originalEndsAt = CarbonImmutable::parse('2026-08-14 10:00:00', 'UTC');
        $original = app(CreateAppointmentAction::class)->handle(
            new AppointmentCreationData(
                service: $service,
                startsAt: $originalStartsAt,
                booking: new AppointmentBookingData(
                    contact: $contact,
                    source: 'crm',
                ),
                idempotencyKey: 'range-reschedule-original',
                endsAt: $originalEndsAt,
            ),
        );
        $replacementStartsAt = CarbonImmutable::parse('2026-08-18 11:00:00', 'UTC');

        $replacement = app(RescheduleAppointmentToSlotAction::class)->handle(
            appointment: $original,
            startsAt: $replacementStartsAt,
            idempotencyKey: 'range-reschedule-replacement',
            lifecycle: new AppointmentLifecycleContext(
                source: 'crm',
                reason: 'range_reschedule_test',
                occurredAt: CarbonImmutable::now('UTC'),
            ),
        );

        $this->assertSame(Appointment::STATUS_CANCELED, $original->refresh()->status);
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertTrue($replacement->starts_at?->equalTo($replacementStartsAt));
        $this->assertTrue($replacement->ends_at?->equalTo(
            $replacementStartsAt->addDays(2),
        ));
    }

    private function rangeService(array $attributes = []): BookableService
    {
        return BookableService::factory()
            ->rangeDuration(
                defaultMinutes: 2880,
                minimumMinutes: 1440,
                maximumMinutes: 10080,
            )
            ->create([
                'key' => 'range-service-'.uniqid(),
                'slot_interval_minutes' => 60,
                'minimum_notice_minutes' => 0,
                'booking_horizon_days' => 30,
                'capacity' => 1,
                'timezone' => 'UTC',
                ...$attributes,
            ]);
    }

    /**
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedRangeService(): array
    {
        $host = SchedulingHost::factory()->create([
            'capacity' => 1,
            'timezone' => 'UTC',
        ]);
        $service = $this->rangeService([
            'is_public' => true,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
        ]);

        return [$service, $host];
    }

    private function absoluteAvailability(
        BookableService $service,
        string $startsAt,
        string $endsAt,
        ?SchedulingHost $host = null,
    ): void {
        $factory = SchedulingAvailabilityWindow::factory();

        if ($host instanceof SchedulingHost) {
            $factory = $factory->forServiceAndHost($service, $host);
        } else {
            $factory = $factory->serviceWide($service);
        }

        $factory
            ->absolute(
                CarbonImmutable::parse($startsAt, 'UTC'),
                CarbonImmutable::parse($endsAt, 'UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }
}