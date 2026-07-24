<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Scheduling\Actions\CreateAppointmentAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\AppointmentCreationData;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CreateAppointmentActionTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_direct_creation_builds_scheduled_appointment_attendee_lifecycle_and_replays_idempotently(): void
    {
        [$service, $host] = $this->hostedService([
            'name' => 'Strategy Session',
            'location_type' => 'virtual',
            'location_details' => [
                'provider' => 'internal',
                'instructions' => 'A link will be supplied separately.',
            ],
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability($service, $host, $startsAt, $startsAt->addHour());
        $contact = Contact::factory()->create([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'phone' => '15555550100',
        ]);
        $sourceContext = ContactImportBatch::factory()->create([
            'name' => 'Manual Scheduling Source',
        ]);
        $creator = User::factory()->create();
        $occurredAt = CarbonImmutable::now('UTC');
        $data = new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            idempotencyKey: 'crm-create-strategy-session-1',
            booking: new AppointmentBookingData(
                contact: $contact,
                title: 'Custom appointment title',
                description: 'Discuss the requested service.',
                sourceContext: $sourceContext,
                createdBy: $creator,
                source: 'crm',
                appointmentMeta: [
                    'request' => [
                        'locale' => 'en-US',
                    ],
                ],
                attendeeMeta: [
                    'request' => [
                        'channel' => 'phone',
                    ],
                ],
            ),
            lifecycle: new AppointmentLifecycleContext(
                actor: $creator,
                source: 'crm',
                reason: 'crm_manual_create',
                occurredAt: $occurredAt,
                context: [
                    'surface' => 'crm_scheduling',
                ],
            ),
        );
        $action = app(CreateAppointmentAction::class);

        $appointment = $action->handle($data);
        $replayed = $action->handle($data);

        $this->assertSame($appointment->id, $replayed->id);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame('crm-create-strategy-session-1', $appointment->idempotency_key);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame($service->id, $appointment->bookable_service_id);
        $this->assertSame($host->id, $appointment->scheduling_host_id);
        $this->assertSame($contact->id, $appointment->contact_id);
        $this->assertTrue($appointment->primaryAttendee->is($contact));
        $this->assertTrue($appointment->sourceContext->is($sourceContext));
        $this->assertTrue($appointment->createdBy->is($creator));
        $this->assertTrue($appointment->starts_at->equalTo($startsAt));
        $this->assertTrue($appointment->ends_at->equalTo($startsAt->addHour()));
        $this->assertSame('Custom appointment title', $appointment->title);
        $this->assertSame('virtual', $appointment->location_type);
        $this->assertSame('internal', data_get($appointment->location_details, 'provider'));
        $this->assertSame(
            'A link will be supplied separately.',
            data_get($appointment->location_details, 'instructions'),
        );
        $this->assertSame('UTC', $appointment->timezone);
        $this->assertSame('crm', $appointment->source);
        $this->assertSame('direct', data_get($appointment->meta, 'creation.mode'));
        $this->assertSame('en-US', data_get($appointment->meta, 'request.locale'));

        $attendee = $appointment->attendees()->sole();

        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendee->status);
        $this->assertSame('primary', $attendee->role);
        $this->assertSame($contact->id, $attendee->contact_id);
        $this->assertTrue($attendee->attendee->is($contact));
        $this->assertSame('Alex Example', $attendee->name);
        $this->assertSame('alex@example.test', $attendee->email);
        $this->assertSame('15555550100', $attendee->phone);
        $this->assertTrue($attendee->responded_at->equalTo($occurredAt));
        $this->assertSame('direct', data_get($attendee->meta, 'creation.mode'));
        $this->assertSame('phone', data_get($attendee->meta, 'request.channel'));

        $event = $appointment->lifecycleEvents()->sole();
        $outbox = AutomationEventOutboxEvent::query()->sole();

        $this->assertSame(AppointmentLifecycleEvent::EVENT_SCHEDULED, $event->event_key);
        $this->assertNull($event->from_status);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $event->to_status);
        $this->assertSame('crm', $event->source);
        $this->assertSame('crm_manual_create', $event->reason);
        $this->assertSame('crm_scheduling', data_get($event->context, 'surface'));
        $this->assertSame('appointment.scheduled', $outbox->event_key);
        $this->assertSame($appointment->id, (int) $outbox->subject_id);
        $this->assertSame($contact->id, $outbox->contact_id);
        $this->assertSame(1, $appointment->attendees()->count());
        $this->assertSame(1, $appointment->lifecycleEvents()->count());
        $this->assertSame(1, AutomationEventOutboxEvent::query()->count());
    }

    public function test_confirmation_required_service_creates_pending_invited_appointment(): void
    {
        [$service, $host] = $this->hostedService([
            'requires_confirmation' => true,
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-04 10:00:00', 'UTC');
        $this->absoluteAvailability($service, $host, $startsAt, $startsAt->addHour());
        $contact = Contact::factory()->create();

        $appointment = app(CreateAppointmentAction::class)->handle(
            new AppointmentCreationData(
                service: $service,
                host: $host,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(
                    contact: $contact,
                    source: 'crm',
                ),
                idempotencyKey: 'pending-direct-create',
            ),
        );
        $attendee = $appointment->attendees()->sole();
        $event = $appointment->lifecycleEvents()->sole();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertNull($appointment->confirmed_at);
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $attendee->status);
        $this->assertNull($attendee->responded_at);
        $this->assertSame(AppointmentLifecycleEvent::EVENT_CREATED, $event->event_key);
        $this->assertSame(Appointment::STATUS_PENDING, $event->to_status);
        $this->assertSame('appointment.created', AutomationEventOutboxEvent::query()->sole()->event_key);
    }

    public function test_unhosted_services_create_without_a_host_but_assigned_services_require_one(): void
    {
        $unhosted = $this->service();
        $unhostedStartsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability(
            service: $unhosted,
            host: null,
            startsAt: $unhostedStartsAt,
            endsAt: $unhostedStartsAt->addHour(),
        );

        $appointment = app(CreateAppointmentAction::class)->handle(
            new AppointmentCreationData(
                service: $unhosted,
                startsAt: $unhostedStartsAt,
                booking: new AppointmentBookingData(
                    contact: Contact::factory()->create(),
                    source: 'crm',
                ),
                idempotencyKey: 'unhosted-direct-create',
            ),
        );

        $this->assertNull($appointment->scheduling_host_id);

        [$hosted] = $this->hostedService();
        $hostedStartsAt = CarbonImmutable::parse('2026-08-04 11:00:00', 'UTC');

        try {
            app(CreateAppointmentAction::class)->handle(
                new AppointmentCreationData(
                    service: $hosted,
                    startsAt: $hostedStartsAt,
                    booking: new AppointmentBookingData(
                        contact: Contact::factory()->create(),
                        source: 'crm',
                    ),
                    idempotencyKey: 'missing-required-host',
                ),
            );
            $this->fail('Expected an assigned service without an explicit host to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explicit', $exception->getMessage());
        }
    }

    public function test_inactive_or_unassigned_hosts_are_rejected(): void
    {
        [$service, $assignedHost] = $this->hostedService();
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability($service, $assignedHost, $startsAt, $startsAt->addHour());
        $unassignedHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $service,
                host: $unassignedHost,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'unassigned-host',
            ),
            'assigned',
        );

        $assignedHost->forceFill([
            'status' => SchedulingHost::STATUS_INACTIVE,
        ])->save();

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $service,
                host: $assignedHost,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'inactive-host',
            ),
            'no longer available',
        );
    }

    public function test_current_availability_minimum_notice_and_booking_horizon_are_authoritative(): void
    {
        [$missingWindowService, $missingWindowHost] = $this->hostedService();
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $missingWindowService,
                host: $missingWindowHost,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'missing-window',
            ),
            'no longer available',
        );

        [$noticeService, $noticeHost] = $this->hostedService([
            'minimum_notice_minutes' => 120,
        ]);
        $insideNotice = CarbonImmutable::now('UTC')->addHour();
        $this->absoluteAvailability(
            $noticeService,
            $noticeHost,
            $insideNotice,
            $insideNotice->addHour(),
        );

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $noticeService,
                host: $noticeHost,
                startsAt: $insideNotice,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'inside-minimum-notice',
            ),
            'no longer available',
        );

        [$horizonService, $horizonHost] = $this->hostedService([
            'booking_horizon_days' => 1,
        ]);
        $outsideHorizon = CarbonImmutable::now('UTC')->addDays(2);
        $this->absoluteAvailability(
            $horizonService,
            $horizonHost,
            $outsideHorizon,
            $outsideHorizon->addHour(),
        );

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $horizonService,
                host: $horizonHost,
                startsAt: $outsideHorizon,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'outside-booking-horizon',
            ),
            'no longer available',
        );
    }

    public function test_existing_appointments_and_active_holds_block_direct_creation(): void
    {
        [$appointmentService, $appointmentHost] = $this->hostedService();
        $appointmentStartsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability(
            $appointmentService,
            $appointmentHost,
            $appointmentStartsAt,
            $appointmentStartsAt->addHour(),
        );
        Appointment::factory()->create([
            'bookable_service_id' => $appointmentService->id,
            'scheduling_host_id' => $appointmentHost->id,
            'status' => Appointment::STATUS_SCHEDULED,
            'starts_at' => $appointmentStartsAt,
            'ends_at' => $appointmentStartsAt->addHour(),
        ]);

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $appointmentService,
                host: $appointmentHost,
                startsAt: $appointmentStartsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'blocked-by-appointment',
            ),
            'no longer available',
        );

        [$holdService, $holdHost] = $this->hostedService();
        $holdStartsAt = CarbonImmutable::parse('2026-08-04 11:00:00', 'UTC');
        $this->absoluteAvailability(
            $holdService,
            $holdHost,
            $holdStartsAt,
            $holdStartsAt->addHour(),
        );
        $this->holdSlot(
            $this->slotAt($holdService, $holdHost, $holdStartsAt),
            'direct-create-capacity-hold',
        );

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $holdService,
                host: $holdHost,
                startsAt: $holdStartsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'blocked-by-hold',
            ),
            'no longer available',
        );
    }

    public function test_host_capacity_is_shared_across_services_for_direct_creation(): void
    {
        $host = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
        $firstService = $this->service();
        $secondService = $this->service();
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');

        foreach ([$firstService, $secondService] as $service) {
            BookableServiceHost::factory()->create([
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
                'is_active' => true,
            ]);
            $this->absoluteAvailability($service, $host, $startsAt, $startsAt->addHour());
        }

        app(CreateAppointmentAction::class)->handle(
            new AppointmentCreationData(
                service: $firstService,
                host: $host,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'shared-host-first',
            ),
        );

        $this->assertCreationDomainFailure(
            new AppointmentCreationData(
                service: $secondService,
                host: $host,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: Contact::factory()->create()),
                idempotencyKey: 'shared-host-second',
            ),
            'no longer available',
        );
    }

    public function test_idempotency_key_reuse_for_another_target_is_rejected(): void
    {
        [$service, $host] = $this->hostedService();
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability($service, $host, $startsAt, $startsAt->addHours(2));
        $contact = Contact::factory()->create();
        $key = 'direct-create-conflict';

        app(CreateAppointmentAction::class)->handle(
            new AppointmentCreationData(
                service: $service,
                host: $host,
                startsAt: $startsAt,
                booking: new AppointmentBookingData(contact: $contact),
                idempotencyKey: $key,
            ),
        );

        $otherService = $this->service();
        $otherHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $otherHost->id,
            'is_active' => true,
        ]);
        $otherContact = Contact::factory()->create();
        $otherSubject = ContactImportBatch::factory()->create();

        $this->assertIdempotencyConflict(new AppointmentCreationData(
            service: $otherService,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(contact: $contact),
            idempotencyKey: $key,
        ));
        $this->assertIdempotencyConflict(new AppointmentCreationData(
            service: $service,
            host: $otherHost,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(contact: $contact),
            idempotencyKey: $key,
        ));
        $this->assertIdempotencyConflict(new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt->addHour(),
            booking: new AppointmentBookingData(contact: $contact),
            idempotencyKey: $key,
        ));
        $this->assertIdempotencyConflict(new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(contact: $otherContact),
            idempotencyKey: $key,
        ));
        $this->assertIdempotencyConflict(new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(
                contact: $contact,
                primaryAttendee: $otherSubject,
            ),
            idempotencyKey: $key,
        ));

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_inactive_services_are_rejected_without_partial_records(): void
    {
        [$service, $host] = $this->hostedService();
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00', 'UTC');
        $this->absoluteAvailability($service, $host, $startsAt, $startsAt->addHour());
        $data = new AppointmentCreationData(
            service: $service,
            host: $host,
            startsAt: $startsAt,
            booking: new AppointmentBookingData(contact: Contact::factory()->create()),
            idempotencyKey: 'inactive-service',
        );

        $service->forceFill([
            'status' => BookableService::STATUS_INACTIVE,
        ])->save();

        $this->assertCreationDomainFailure($data, 'no longer available');
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, AppointmentAttendee::query()->count());
        $this->assertSame(0, AppointmentLifecycleEvent::query()->count());
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
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
        $service = $this->service($serviceAttributes);
        $host = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
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

    /**
     * @param array<string, mixed> $attributes
     */
    private function service(array $attributes = []): BookableService
    {
        return BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$attributes,
        ]);
    }

    private function absoluteAvailability(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $capacity = 1,
    ): SchedulingAvailabilityWindow {
        $factory = SchedulingAvailabilityWindow::factory()->absolute(
            $startsAt,
            $endsAt,
        );

        $factory = $host !== null
            ? $factory->forServiceAndHost($service, $host)
            : $factory->serviceWide($service);

        return $factory->create([
            'timezone' => 'UTC',
            'capacity' => $capacity,
        ]);
    }

    private function slotAt(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
    ): BookableSlot {
        $endsAt = $startsAt->addMinutes((int) $service->duration_minutes);
        $slots = app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                host: $host,
                displayTimezone: $service->timezone,
                evaluatedAt: CarbonImmutable::now('UTC'),
            ),
        );

        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($startsAt)
                && $slot->endsAt->equalTo($endsAt)
            ) {
                return $slot;
            }
        }

        $this->fail('Expected the requested slot to be available.');
    }

    private function holdSlot(BookableSlot $slot, string $idempotencyKey): BookingHold
    {
        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        return app(CreateBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: $idempotencyKey,
        );
    }

    private function assertCreationDomainFailure(
        AppointmentCreationData $data,
        string $messageFragment,
    ): void {
        try {
            app(CreateAppointmentAction::class)->handle($data);
            $this->fail('Expected direct appointment creation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                $messageFragment,
                $exception->getMessage(),
            );
        }
    }

    private function assertIdempotencyConflict(AppointmentCreationData $data): void
    {
        try {
            app(CreateAppointmentAction::class)->handle($data);
            $this->fail('Expected conflicting direct appointment idempotency to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'idempotency key',
                $exception->getMessage(),
            );
        }
    }
}