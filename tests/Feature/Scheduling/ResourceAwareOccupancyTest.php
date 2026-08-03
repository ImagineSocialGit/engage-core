<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\ConvertBookingHoldToAppointmentAction;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Actions\ReleaseBookingHoldAction;
use App\Modules\Scheduling\Actions\RescheduleAppointmentAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AppointmentRescheduleData;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Jobs\ExpireBookingHoldsJob;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Models\SchedulingResourceOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceAwareOccupancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-03 12:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
        config()->set('scheduling.booking_holds.expiration_batch_size', 500);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_services_without_requirements_keep_existing_capacity_behavior(): void
    {
        [$service, $host] = $this->hostedService(
            service: ['capacity' => 4],
            host: ['capacity' => 2],
            assignment: ['capacity_override' => 3],
            windowCapacity: 5,
        );

        $slot = $this->slotAt($service, $host, '2026-08-04 09:00:00');

        $this->assertSame(2, $slot->capacity);
        $this->assertSame(2, $slot->remainingCapacity);
        $this->assertSame(0, SchedulingResourceOccupancy::query()->count());
    }

    public function test_selective_resources_allow_phone_work_during_physical_presence(): void
    {
        $host = SchedulingHost::factory()->create([
            'capacity' => 2,
            'timezone' => 'UTC',
        ]);
        $onsite = $this->serviceForHost($host, 'onsite', ['capacity' => 2]);
        $phone = $this->serviceForHost($host, 'phone', ['capacity' => 2]);
        $physical = SchedulingResource::query()->create([
            'key' => 'physical_presence',
            'name' => 'Physical presence',
        ]);
        $phoneAttention = SchedulingResource::query()->create([
            'key' => 'phone_attention',
            'name' => 'Phone attention',
        ]);

        $this->hostCapacity($host, $physical, 1);
        $this->hostCapacity($host, $phoneAttention, 1);
        $this->requirement($onsite, $physical, 1);
        $this->requirement($phone, $phoneAttention, 1);

        $this->createAppointment(
            service: $onsite,
            host: $host,
            startsAt: '2026-08-04 10:00:00',
            idempotencyKey: 'onsite-existing',
        );

        $this->assertNull($this->findSlot($onsite, $host, '2026-08-04 10:00:00'));
        $this->assertInstanceOf(
            BookableSlot::class,
            $this->findSlot($phone, $host, '2026-08-04 10:00:00'),
        );
        $this->assertSame(1, SchedulingResourceOccupancy::query()->count());
        $this->assertSame(
            $physical->id,
            SchedulingResourceOccupancy::query()->sole()->scheduling_resource_id,
        );
    }

    public function test_requirement_quantity_limits_reported_and_remaining_capacity(): void
    {
        [$service, $host] = $this->hostedService(
            service: ['capacity' => 10],
            host: ['capacity' => 10],
        );
        $resource = SchedulingResource::query()->create([
            'key' => 'crew_members',
            'name' => 'Crew members',
        ]);

        $this->hostCapacity($host, $resource, 5);
        $this->requirement($service, $resource, 2);

        $before = $this->slotAt($service, $host, '2026-08-04 09:00:00');

        $this->assertSame(2, $before->capacity);
        $this->assertSame(2, $before->remainingCapacity);

        $this->createAppointment(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 09:00:00',
            idempotencyKey: 'crew-capacity-first',
        );

        $after = $this->slotAt($service, $host, '2026-08-04 09:00:00');

        $this->assertSame(2, $after->capacity);
        $this->assertSame(1, $after->remainingCapacity);
        $this->assertSame(2, SchedulingResourceOccupancy::query()->sole()->quantity);
    }

    public function test_multiple_requirements_use_the_lowest_resource_capacity(): void
    {
        [$service, $host] = $this->hostedService(
            service: ['capacity' => 10],
            host: ['capacity' => 10],
        );
        $room = SchedulingResource::query()->create([
            'key' => 'room',
            'name' => 'Room',
        ]);
        $specialist = SchedulingResource::query()->create([
            'key' => 'specialist',
            'name' => 'Specialist',
        ]);

        $this->hostCapacity($host, $room, 4);
        $this->hostCapacity($host, $specialist, 2);
        $this->requirement($service, $room, 1);
        $this->requirement($service, $specialist, 1);

        $slot = $this->slotAt($service, $host, '2026-08-04 09:00:00');

        $this->assertSame(2, $slot->capacity);
        $this->assertSame(2, $slot->remainingCapacity);
    }

    public function test_missing_inactive_or_unhosted_resource_capacity_closes_availability(): void
    {
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'vehicle',
            'name' => 'Vehicle',
        ]);

        $this->requirement($service, $resource, 1);

        $this->assertNull($this->findSlot($service, $host, '2026-08-04 09:00:00'));

        $hostCapacity = $this->hostCapacity($host, $resource, 1);
        $hostCapacity->forceFill(['is_active' => false])->save();

        $this->assertNull($this->findSlot($service, $host, '2026-08-04 09:00:00'));

        $hostCapacity->forceFill(['is_active' => true])->save();
        $resource->forceFill(['status' => SchedulingResource::STATUS_INACTIVE])->save();

        $this->assertNull($this->findSlot($service, $host, '2026-08-04 09:00:00'));

        $resource->forceFill(['status' => SchedulingResource::STATUS_ACTIVE])->save();

        $unhosted = BookableService::factory()->create([
            'key' => 'unhosted-resource-service',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
        ]);
        SchedulingAvailabilityWindow::factory()
            ->serviceWide($unhosted)
            ->absolute(
                CarbonImmutable::parse('2026-08-04 08:00:00', 'UTC'),
                CarbonImmutable::parse('2026-08-04 17:00:00', 'UTC'),
            )
            ->create(['timezone' => 'UTC']);
        $this->requirement($unhosted, $resource, 1);

        $this->assertNull($this->findSlot($unhosted, null, '2026-08-04 09:00:00'));
    }

    public function test_direct_creation_snapshots_resources_once_and_replays_idempotently(): void
    {
        [$service, $host] = $this->hostedService(
            service: [
                'buffer_before_minutes' => 10,
                'buffer_after_minutes' => 20,
            ],
        );
        $resource = SchedulingResource::query()->create([
            'key' => 'equipment',
            'name' => 'Equipment',
        ]);
        $this->hostCapacity($host, $resource, 2);
        $requirement = $this->requirement($service, $resource, 1);
        $data = $this->creationData(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 11:00:00',
            idempotencyKey: 'direct-resource-snapshot',
        );
        $action = app(CreateAppointmentAction::class);

        $appointment = $action->handle($data);
        $requirement->forceFill(['quantity' => 2])->save();
        $replayed = $action->handle($data);
        $occupancy = SchedulingResourceOccupancy::query()->sole();

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertSame(1, SchedulingResourceOccupancy::query()->count());
        $this->assertSame(1, $occupancy->quantity);
        $this->assertSame($appointment->id, $occupancy->appointment_id);
        $this->assertNull($occupancy->booking_hold_id);
        $this->assertSame(
            '2026-08-04 10:50:00',
            $occupancy->occupancy_starts_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-04 12:20:00',
            $occupancy->occupancy_ends_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_hold_snapshot_transfers_to_appointment_without_recalculation(): void
    {
        [$service, $host] = $this->hostedService(
            service: [
                'buffer_before_minutes' => 15,
                'buffer_after_minutes' => 20,
            ],
        );
        $resource = SchedulingResource::query()->create([
            'key' => 'physical_presence',
            'name' => 'Physical presence',
        ]);
        $hostCapacity = $this->hostCapacity($host, $resource, 2);
        $requirement = $this->requirement($service, $resource, 1);
        $hold = $this->holdAt(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 11:00:00',
            idempotencyKey: 'hold-resource-snapshot',
        );
        $holdOccupancy = SchedulingResourceOccupancy::query()->sole();

        $this->assertSame($hold->id, $holdOccupancy->booking_hold_id);
        $this->assertNull($holdOccupancy->appointment_id);
        $this->assertSame('2026-08-04 10:45:00', $holdOccupancy->occupancy_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 12:20:00', $holdOccupancy->occupancy_ends_at->format('Y-m-d H:i:s'));

        $requirement->forceFill(['quantity' => 2])->save();
        $hostCapacity->forceFill(['capacity' => 2])->save();
        $contact = Contact::factory()->create();
        $action = app(ConvertBookingHoldToAppointmentAction::class);

        $appointment = $action->handle(
            holdId: $hold->hold_id,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'public_booking',
            ),
        );
        $replayed = $action->handle(
            holdId: $hold->hold_id,
            booking: new AppointmentBookingData(
                contact: $contact,
                source: 'public_booking',
            ),
        );
        $appointmentOccupancy = SchedulingResourceOccupancy::query()->sole();

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertSame(1, $appointmentOccupancy->quantity);
        $this->assertSame($appointment->id, $appointmentOccupancy->appointment_id);
        $this->assertNull($appointmentOccupancy->booking_hold_id);
        $this->assertSame(1, SchedulingResourceOccupancy::query()->count());
    }

    public function test_release_and_expiration_remove_temporary_resource_occupancy(): void
    {
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'phone_attention',
            'name' => 'Phone attention',
        ]);
        $this->hostCapacity($host, $resource, 2);
        $this->requirement($service, $resource, 1);
        $released = $this->holdAt(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 09:00:00',
            idempotencyKey: 'released-resource-hold',
        );
        $expiring = $this->holdAt(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 11:00:00',
            idempotencyKey: 'expired-resource-hold',
        );

        $this->assertSame(2, SchedulingResourceOccupancy::query()->count());

        app(ReleaseBookingHoldAction::class)->handle($released->hold_id);

        $this->assertSame(1, SchedulingResourceOccupancy::query()->count());
        $this->assertSame($expiring->id, SchedulingResourceOccupancy::query()->sole()->booking_hold_id);

        CarbonImmutable::setTestNow($expiring->expires_at->addSecond());
        $expiredCount = app(ExpireBookingHoldsJob::class)->handle();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(0, SchedulingResourceOccupancy::query()->count());
        $this->assertSame(
            BookingHold::STATUS_EXPIRED,
            $expiring->refresh()->status,
        );
    }

    public function test_reschedule_conversion_transfers_hold_snapshot_to_replacement(): void
    {
        [$service, $host] = $this->hostedService();
        $resource = SchedulingResource::query()->create([
            'key' => 'physical_presence',
            'name' => 'Physical presence',
        ]);
        $this->hostCapacity($host, $resource, 1);
        $this->requirement($service, $resource, 1);
        $original = $this->createAppointment(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 09:00:00',
            idempotencyKey: 'resource-reschedule-original',
        );
        $slot = $this->slotAt(
            service: $service,
            host: $host,
            startsAt: '2026-08-04 11:00:00',
            rescheduleAppointment: $original,
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $original,
        );
        $hold = app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'resource-reschedule-hold',
        );

        $this->assertSame(2, SchedulingResourceOccupancy::query()->count());

        $replacement = app(RescheduleAppointmentAction::class)->handle(
            new AppointmentRescheduleData($hold->hold_id),
        );
        $occupancies = SchedulingResourceOccupancy::query()
            ->orderBy('appointment_id')
            ->get();

        $this->assertSame(Appointment::STATUS_CANCELED, $original->refresh()->status);
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(2, $occupancies->count());
        $this->assertTrue($occupancies->contains(
            fn (SchedulingResourceOccupancy $occupancy): bool =>
                $occupancy->appointment_id === $original->id
                && $occupancy->booking_hold_id === null,
        ));
        $this->assertTrue($occupancies->contains(
            fn (SchedulingResourceOccupancy $occupancy): bool =>
                $occupancy->appointment_id === $replacement->id
                && $occupancy->booking_hold_id === null,
        ));
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, mixed> $host
     * @param array<string, mixed> $assignment
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(
        array $service = [],
        array $host = [],
        array $assignment = [],
        ?int $windowCapacity = null,
    ): array {
        $hostModel = SchedulingHost::factory()->create([
            'capacity' => 10,
            'timezone' => 'UTC',
            ...$host,
        ]);
        $serviceModel = $this->serviceForHost(
            host: $hostModel,
            key: 'service-'.uniqid(),
            attributes: $service,
            assignment: $assignment,
            windowCapacity: $windowCapacity,
        );

        return [$serviceModel, $hostModel];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $assignment
     */
    private function serviceForHost(
        SchedulingHost $host,
        string $key,
        array $attributes = [],
        array $assignment = [],
        ?int $windowCapacity = null,
    ): BookableService {
        $service = BookableService::factory()->create([
            'key' => $key,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'capacity' => 10,
            'timezone' => 'UTC',
            ...$attributes,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            ...$assignment,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->forServiceAndHost($service, $host)
            ->absolute(
                CarbonImmutable::parse('2026-08-04 08:00:00', 'UTC'),
                CarbonImmutable::parse('2026-08-04 17:00:00', 'UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => $windowCapacity,
            ]);

        return $service;
    }

    private function hostCapacity(
        SchedulingHost $host,
        SchedulingResource $resource,
        int $capacity,
    ): SchedulingHostResource {
        return SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => $capacity,
            'is_active' => true,
        ]);
    }

    private function requirement(
        BookableService $service,
        SchedulingResource $resource,
        int $quantity,
    ): BookableServiceResourceRequirement {
        return BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => $quantity,
            'is_active' => true,
        ]);
    }

    private function createAppointment(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        string $idempotencyKey,
    ): Appointment {
        return app(CreateAppointmentAction::class)->handle(
            $this->creationData(
                service: $service,
                host: $host,
                startsAt: $startsAt,
                idempotencyKey: $idempotencyKey,
            ),
        );
    }

    private function creationData(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        string $idempotencyKey,
    ): AppointmentCreationData {
        return new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: CarbonImmutable::parse($startsAt, 'UTC'),
            booking: new AppointmentBookingData(
                contact: Contact::factory()->create(),
                source: 'crm',
            ),
            idempotencyKey: $idempotencyKey,
        );
    }

    private function holdAt(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        string $idempotencyKey,
        ?Appointment $rescheduleAppointment = null,
    ): BookingHold {
        $slot = $this->slotAt(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            rescheduleAppointment: $rescheduleAppointment,
        );
        $offer = app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $rescheduleAppointment,
        );

        return app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function slotAt(
        BookableService $service,
        ?SchedulingHost $host,
        string $startsAt,
        ?Appointment $rescheduleAppointment = null,
    ): BookableSlot {
        $slot = $this->findSlot(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            rescheduleAppointment: $rescheduleAppointment,
        );

        if (! $slot instanceof BookableSlot) {
            $this->fail('Expected the requested slot to be available.');
        }

        return $slot;
    }

    private function findSlot(
        BookableService $service,
        ?SchedulingHost $host,
        string $startsAt,
        ?Appointment $rescheduleAppointment = null,
    ): ?BookableSlot {
        $target = CarbonImmutable::parse($startsAt, 'UTC');
        $slots = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-08-04 08:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-08-04 17:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                rescheduleAppointment: $rescheduleAppointment,
            ),
        );

        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($target)) {
                return $slot;
            }
        }

        return null;
    }
}