<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RescheduleSlotOfferAndBookingHoldActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC'),
        );

        config()->set('scheduling.slot_offers.ttl_seconds', 300);
        config()->set('scheduling.booking_holds.ttl_seconds', 600);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_reschedule_availability_excludes_only_the_original_appointment(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );

        $this->assertNotContains(
            '2026-07-28 09:00:00',
            $this->slotStartValues($this->slots($service, $host)),
        );
        $this->assertContains(
            '2026-07-28 09:00:00',
            $this->slotStartValues($this->slots($service, $host, $source)),
        );

        $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );

        $this->assertNotContains(
            '2026-07-28 09:00:00',
            $this->slotStartValues($this->slots($service, $host, $source)),
        );
    }

    public function test_reschedule_offer_persists_the_trusted_source_and_creates_an_overlapping_hold(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
            status: Appointment::STATUS_CONFIRMED,
        );
        $slot = $this->slotAt(
            $service,
            $host,
            '2026-07-28 09:00:00',
            $source,
        );

        $offer = app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $source,
        );
        $holdAction = app(CreateBookingHoldAction::class);
        $hold = $holdAction->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'reschedule-overlap',
        );
        $replayed = $holdAction->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'reschedule-overlap',
        );

        $this->assertSame($hold->id, $replayed->id);
        $this->assertTrue(Str::isUuid($offer->offer_id));
        $this->assertTrue($offer->isRescheduleOffer());
        $this->assertTrue($offer->isForRescheduleOf($source));
        $this->assertSame($source->id, $offer->reschedule_appointment_id);
        $this->assertSame($source->id, $offer->rescheduleAppointment->id);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->status);
        $this->assertTrue($hold->starts_at->equalTo($source->starts_at));
        $this->assertSame(
            $source->id,
            $hold->bookableSlotOffer->reschedule_appointment_id,
        );
    }

    public function test_ordinary_offers_remain_unscoped_to_rescheduling(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $slot = $this->slots($service, $host)[0];

        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        $this->assertFalse($offer->isRescheduleOffer());
        $this->assertNull($offer->reschedule_appointment_id);
        $this->assertNull($offer->rescheduleAppointment);
    }

    public function test_reschedule_search_and_offer_reject_a_source_from_another_service(): void
    {
        [$service, $host] = $this->hostedService();
        [$otherService] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $source = Appointment::factory()->create([
            'bookable_service_id' => $otherService->id,
            'scheduling_host_id' => null,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-28 10:00:00', 'UTC'),
        ]);

        try {
            $this->slots($service, $host, $source);
            $this->fail('Expected cross-service reschedule availability to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('searched service', $exception->getMessage());
        }

        $slot = $this->slots($service, $host)[0];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belongs to another service');

        app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $source,
        );
    }

    public function test_terminal_and_already_replaced_appointments_cannot_issue_reschedule_offers(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);

        foreach ([
            Appointment::STATUS_CANCELED,
            Appointment::STATUS_COMPLETED,
            Appointment::STATUS_NO_SHOW,
        ] as $index => $status) {
            $source = $this->appointment(
                service: $service,
                host: $host,
                startsAt: '2026-07-28 09:00:00',
                status: Appointment::STATUS_SCHEDULED,
            );
            $slot = $this->slotAt(
                $service,
                $host,
                '2026-07-28 09:00:00',
                $source,
            );
            $source->forceFill(['status' => $status])->save();

            try {
                app(IssueBookableSlotOfferAction::class)->handle(
                    slot: $slot,
                    rescheduleAppointment: $source,
                );
                $this->fail("Expected terminal status [{$status}] to be rejected at index {$index}.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString('cannot be rescheduled', $exception->getMessage());
            }

            $source->delete();
        }

        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );
        $slot = $this->slotAt(
            $service,
            $host,
            '2026-07-28 09:00:00',
            $source,
        );
        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'rescheduled_from_id' => $source->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-07-28 10:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-28 11:00:00', 'UTC'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been rescheduled');

        app(IssueBookableSlotOfferAction::class)->handle(
            slot: $slot,
            rescheduleAppointment: $source,
        );
    }

    public function test_hold_creation_revalidates_stale_reschedule_source_state(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );
        $offer = $this->issueRescheduleOffer($service, $host, $source, '2026-07-28 09:00:00');

        $source->forceFill([
            'status' => Appointment::STATUS_CANCELED,
            'canceled_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot be rescheduled');

        app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: 'stale-reschedule-status',
        );
    }

    public function test_hold_creation_rejects_a_replacement_or_new_competing_occupancy_added_after_offer_issuance(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );
        $replacedOffer = $this->issueRescheduleOffer(
            $service,
            $host,
            $source,
            '2026-07-28 09:00:00',
        );

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'rescheduled_from_id' => $source->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-07-28 10:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-07-28 11:00:00', 'UTC'),
        ]);

        try {
            app(CreateBookingHoldAction::class)->handle(
                offerId: $replacedOffer->offer_id,
                idempotencyKey: 'already-replaced-source',
            );
            $this->fail('Expected an already-replaced source to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already been rescheduled', $exception->getMessage());
        }

        $secondSource = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 11:00:00',
        );
        $competingOffer = $this->issueRescheduleOffer(
            $service,
            $host,
            $secondSource,
            '2026-07-28 11:00:00',
        );
        $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 11:00:00',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('available capacity');

        app(CreateBookingHoldAction::class)->handle(
            offerId: $competingOffer->offer_id,
            idempotencyKey: 'new-competing-occupancy',
        );
    }

    public function test_reschedule_exclusion_ignores_source_buffers_but_preserves_other_appointment_buffers(): void
    {
        [$service, $host] = $this->hostedService([
            'buffer_after_minutes' => 15,
        ]);
        $this->absoluteAvailability($service, $host);
        $source = $this->appointment(
            service: $service,
            host: $host,
            startsAt: '2026-07-28 09:00:00',
        );

        $this->assertContains(
            '2026-07-28 10:00:00',
            $this->slotStartValues($this->slots($service, $host, $source)),
        );

        $otherService = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'buffer_before_minutes' => 15,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
        $this->appointment(
            service: $otherService,
            host: $host,
            startsAt: '2026-07-28 11:15:00',
        );

        $this->assertNotContains(
            '2026-07-28 10:00:00',
            $this->slotStartValues($this->slots($service, $host, $source)),
        );
    }

    /**
     * @param array<string, mixed> $serviceAttributes
     * @param array<string, mixed> $hostAttributes
     * @param array<string, mixed> $assignmentAttributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(
        array $serviceAttributes = [],
        array $hostAttributes = [],
        array $assignmentAttributes = [],
    ): array {
        $service = BookableService::factory()->create([
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$serviceAttributes,
        ]);
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$hostAttributes,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
            ...$assignmentAttributes,
        ]);

        return [$service, $host];
    }

    private function absoluteAvailability(
        BookableService $service,
        SchedulingHost $host,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-28 13:00:00', 'UTC'),
            )
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    private function appointment(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        string $status = Appointment::STATUS_SCHEDULED,
    ): Appointment {
        $startsAt = CarbonImmutable::parse($startsAt, 'UTC');

        return Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) $service->duration_minutes),
        ]);
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function slots(
        BookableService $service,
        SchedulingHost $host,
        ?Appointment $rescheduleAppointment = null,
    ): array {
        return app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-07-28 13:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
                rescheduleAppointment: $rescheduleAppointment,
            ),
        );
    }

    private function slotAt(
        BookableService $service,
        SchedulingHost $host,
        string $startsAt,
        ?Appointment $rescheduleAppointment = null,
    ): BookableSlot {
        foreach ($this->slots($service, $host, $rescheduleAppointment) as $slot) {
            if ($slot->startsAt->format('Y-m-d H:i:s') === $startsAt) {
                return $slot;
            }
        }

        $this->fail("Expected slot [{$startsAt}] to be available.");
    }

    private function issueRescheduleOffer(
        BookableService $service,
        SchedulingHost $host,
        Appointment $source,
        string $startsAt,
    ): BookableSlotOffer {
        return app(IssueBookableSlotOfferAction::class)->handle(
            slot: $this->slotAt($service, $host, $startsAt, $source),
            rescheduleAppointment: $source,
        );
    }

    /**
     * @param array<int, BookableSlot> $slots
     * @return array<int, string>
     */
    private function slotStartValues(array $slots): array
    {
        return array_map(
            static fn (BookableSlot $slot): string => $slot->startsAt->format('Y-m-d H:i:s'),
            $slots,
        );
    }
}