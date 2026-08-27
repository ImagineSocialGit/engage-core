<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\CreatePublicBookingHoldAction;
use App\Modules\Scheduling\Actions\IssuePublicBookingSlotOfferAction;
use App\Modules\Scheduling\Actions\ReleaseBookingHoldAction;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_hold_completion_creates_a_contact_scheduled_appointment_and_attendee_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('consultation');
        $hold = $this->activeHold($service, '2026-07-25 09:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->get($holdUrl)
            ->assertOk()
            ->assertSee('Complete booking')
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="phone"', false);

        $response = $this->post($holdUrl, [
            'first_name' => '  Jamie  ',
            'last_name' => '  Visitor  ',
            'email' => '  JAMIE@EXAMPLE.TEST  ',
            'phone' => '  (555) 555-0123  ',
            'public_submission_attempt_id' => '2bc92de0-55fe-4dbd-9287-58c77d684a2f',
        ]);

        $response->assertRedirect($holdUrl);

        $contact = Contact::query()->sole();
        $appointment = Appointment::query()->sole();
        $attendee = AppointmentAttendee::query()->sole();
        $hold->refresh();

        $this->assertSame('jamie@example.test', $contact->email);
        $this->assertSame('Jamie', $contact->first_name);
        $this->assertSame('Visitor', $contact->last_name);
        $this->assertSame('Jamie Visitor', $contact->name);
        $this->assertSame('+15555550123', $contact->phone);
        $this->assertSame('public_booking', $contact->source);
        $this->assertSame('scheduling', $contact->subsource);

        $this->assertSame(BookingHold::STATUS_CONVERTED, $hold->status);
        $this->assertSame($appointment->id, $hold->appointment_id);
        $this->assertSame($contact->id, $appointment->contact_id);
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame('public_booking', $appointment->source);
        $this->assertSame(
            '2bc92de0-55fe-4dbd-9287-58c77d684a2f',
            data_get($appointment->meta, 'reporting.public_submission_attempt_id'),
        );
        $this->assertSame(
            'scheduling.public_booking.communications',
            data_get($appointment->meta, 'public_booking_disclosure.key'),
        );
        $this->assertNotNull(data_get($appointment->meta, 'public_booking_disclosure.accepted_at'));

        $this->assertSame($appointment->id, $attendee->appointment_id);
        $this->assertSame($contact->id, $attendee->contact_id);
        $this->assertSame($contact->getMorphClass(), $attendee->attendee_type);
        $this->assertSame($contact->id, $attendee->attendee_id);
        $this->assertSame('Jamie Visitor', $attendee->name);
        $this->assertSame('jamie@example.test', $attendee->email);
        $this->assertSame('+15555550123', $attendee->phone);
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendee->status);
        $this->assertNotNull($attendee->responded_at);

        $this->assertDatabaseHas('appointment_lifecycle_events', [
            'appointment_id' => $appointment->id,
            'event_key' => AppointmentLifecycleEvent::EVENT_SCHEDULED,
            'to_status' => Appointment::STATUS_SCHEDULED,
        ]);

        $this->get($holdUrl)
            ->assertOk()
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="last_name"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('jamie@example.test')
            ->assertDontSee('+15555550123')
            ->assertDontSee('contact_id')
            ->assertDontSee('appointment_id')
            ->assertDontSee('scheduling_host_id')
            ->assertDontSee('remaining_capacity');
    }

    public function test_services_requiring_confirmation_create_a_pending_public_appointment(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService(
            key: 'approval-required',
            requiresConfirmation: true,
        );
        $hold = $this->activeHold($service, '2026-07-25 10:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->post($holdUrl, [
            'first_name' => 'Pending',
            'last_name' => 'Visitor',
            'email' => 'pending@example.test',
        ])->assertRedirect($holdUrl);

        $appointment = Appointment::query()->sole();
        $attendee = AppointmentAttendee::query()->sole();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertSame(AppointmentAttendee::STATUS_INVITED, $attendee->status);
        $this->assertNull($attendee->responded_at);

        $this->get($holdUrl)
            ->assertOk()
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="last_name"', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('Pending Visitor')
            ->assertDontSee('pending@example.test');
    }

    public function test_existing_contacts_are_reused_without_public_overwrites(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $existing = Contact::factory()->create([
            'name' => 'Established Contact',
            'email' => 'existing@example.test',
            'phone' => '+15555550000',
            'source' => 'import',
            'subsource' => 'legacy_crm',
            'meta' => [
                'durable' => [
                    'keep' => true,
                ],
            ],
        ]);
        $service = $this->publicService('existing-contact');
        $hold = $this->activeHold($service, '2026-07-25 11:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->post($holdUrl, [
            'first_name' => 'Submitted Snapshot',
            'last_name' => 'Name',
            'email' => ' EXISTING@EXAMPLE.TEST ',
            'phone' => '+15555559999',
        ])->assertRedirect($holdUrl);

        $existing->refresh();
        $appointment = Appointment::query()->sole();
        $attendee = AppointmentAttendee::query()->sole();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame('Established Contact', $existing->name);
        $this->assertSame('+15555550000', $existing->phone);
        $this->assertSame('import', $existing->source);
        $this->assertSame('legacy_crm', $existing->subsource);
        $this->assertTrue((bool) data_get($existing->meta, 'durable.keep'));

        $this->assertSame($existing->id, $appointment->contact_id);
        $this->assertSame($existing->id, $attendee->contact_id);
        $this->assertSame('Submitted Snapshot Name', $attendee->name);
        $this->assertSame('existing@example.test', $attendee->email);
        $this->assertSame('+15555559999', $attendee->phone);
    }

    public function test_converted_public_hold_replays_do_not_resolve_another_contact(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('replay-completion');
        $hold = $this->activeHold($service, '2026-07-25 12:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->post($holdUrl, [
            'first_name' => 'First',
            'last_name' => 'Visitor',
            'email' => 'first@example.test',
        ])->assertRedirect($holdUrl);

        $firstAppointmentId = Appointment::query()->sole()->id;

        $this->post($holdUrl, [
            'first_name' => 'Replay',
            'last_name' => 'Visitor',
            'email' => 'second@example.test',
            'phone' => '+15555558888',
        ])->assertRedirect($holdUrl);

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_attendees', 1);
        $this->assertDatabaseMissing('contacts', [
            'email' => 'second@example.test',
        ]);
        $this->assertSame($firstAppointmentId, $hold->fresh()->appointment_id);
    }

    public function test_released_and_elapsed_holds_cannot_create_contacts_or_appointments(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('terminal-holds');
        $released = $this->activeHold($service, '2026-07-25 13:00:00 UTC');
        app(ReleaseBookingHoldAction::class)->handle($released->hold_id);
        $releasedUrl = 'https://schedule.test/book/'.$released->hold_id;

        $this->from($releasedUrl)
            ->post($releasedUrl, [
                'first_name' => 'Released',
                'last_name' => 'Visitor',
                'email' => 'released@example.test',
            ])
            ->assertRedirect($releasedUrl)
            ->assertSessionHasErrors('booking');

        config()->set('scheduling.booking_holds.ttl_seconds', 60);
        $elapsed = $this->activeHold($service, '2026-07-25 14:00:00 UTC');
        $elapsedUrl = 'https://schedule.test/book/'.$elapsed->hold_id;
        CarbonImmutable::setTestNow(
            CarbonImmutable::instance($elapsed->expires_at)->utc()->addSecond(),
        );

        $this->from($elapsedUrl)
            ->post($elapsedUrl, [
                'first_name' => 'Elapsed',
                'last_name' => 'Visitor',
                'email' => 'elapsed@example.test',
            ])
            ->assertRedirect($elapsedUrl)
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_attendees', 0);
        $this->assertSame(BookingHold::STATUS_RELEASED, $released->fresh()->status);
        $this->assertSame(BookingHold::STATUS_EXPIRED, $elapsed->fresh()->status);
    }

    public function test_public_completion_validates_attendee_input_and_prohibits_booking_internals(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('validated-completion');
        $hold = $this->activeHold($service, '2026-07-25 15:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->from($holdUrl)
            ->post($holdUrl, [
                'first_name' => ' ',
                'last_name' => ' ',
                'name' => 'Forged Combined Name',
                'email' => 'not-an-email',
                'phone' => str_repeat('1', 256),
                'contact_id' => 999,
                'appointment_id' => 999,
                'bookable_service_id' => 999,
                'scheduling_host_id' => 999,
                'starts_at' => '2030-01-01T00:00:00Z',
                'status' => Appointment::STATUS_CONFIRMED,
                'source' => 'forged',
                'capacity' => 999,
                'offer_id' => (string) Str::uuid(),
            ])
            ->assertRedirect($holdUrl)
            ->assertSessionHasErrors([
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'contact_id',
                'appointment_id',
                'bookable_service_id',
                'scheduling_host_id',
                'starts_at',
                'status',
                'source',
                'capacity',
                'offer_id',
            ]);

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_attendees', 0);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->fresh()->status);
    }

    public function test_phone_appointments_require_a_phone_number_for_public_completion(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = BookableService::factory()->create([
            'key' => 'phone-consultation',
            'name' => 'Phone Consultation',
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'booking_horizon_days' => 10,
            'timezone' => 'UTC',
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => BookableService::REMOTE_METHOD_PHONE,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => true,
        ]);
        $hold = $this->activeHold($service, '2026-07-25 15:00:00 UTC');
        $holdUrl = 'https://schedule.test/book/'.$hold->hold_id;

        $this->get($holdUrl)
            ->assertOk()
            ->assertSee('data-phone-required="true"', false);

        $this->from($holdUrl)
            ->post($holdUrl, [
                'first_name' => 'Phone',
                'last_name' => 'Visitor',
                'email' => 'phone@example.test',
            ])
            ->assertRedirect($holdUrl)
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->fresh()->status);

        $this->post($holdUrl, [
            'first_name' => 'Phone',
            'last_name' => 'Visitor',
            'email' => 'phone@example.test',
            'phone' => '(555) 555-0167',
        ])->assertRedirect($holdUrl);

        $this->assertSame('+15555550167', Contact::query()->sole()->phone);
        $this->assertSame(BookingHold::STATUS_CONVERTED, $hold->fresh()->status);
    }

    public function test_public_completion_route_is_isolated_to_the_configured_host(): void
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
        $this->registerPublicSurface('https://schedule.test');

        $service = $this->publicService('host-isolation');
        $hold = $this->activeHold($service, '2026-07-25 16:00:00 UTC');

        $this->post('https://example.test/book/'.$hold->hold_id, [
            'first_name' => 'Wrong',
            'last_name' => 'Host',
            'email' => 'wrong-host@example.test',
        ])->assertNotFound();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->fresh()->status);
    }

    private function publicService(
        string $key,
        bool $requiresConfirmation = false,
    ): BookableService {
        return BookableService::factory()->create([
            'key' => $key,
            'name' => Str::headline($key),
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'booking_horizon_days' => 10,
            'timezone' => 'UTC',
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => BookableService::REMOTE_METHOD_VIRTUAL_MEETING,
            'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
            'capacity' => 1,
            'requires_confirmation' => $requiresConfirmation,
            'is_public' => true,
        ]);
    }

    private function activeHold(
        BookableService $service,
        string $startsAt,
    ): BookingHold {
        $startsAt = CarbonImmutable::parse($startsAt)->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute($startsAt, $endsAt)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);

        $offer = app(IssuePublicBookingSlotOfferAction::class)->handle(
            service: $service,
            startsAt: $startsAt,
        );

        return app(CreatePublicBookingHoldAction::class)->handle(
            offerId: $offer->offer_id,
            idempotencyKey: (string) Str::uuid(),
        );
    }

    private function registerPublicSurface(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_string($parts['scheme'] ?? null)
            ? strtolower($parts['scheme'])
            : null;
        $host = is_string($parts['host'] ?? null)
            ? strtolower($parts['host'])
            : null;

        $this->assertNotNull($scheme);
        $this->assertNotNull($host);

        config()->set('modules.enabled', [
            ...config('modules.enabled', []),
            'scheduling',
        ]);
        config()->set(
            'messaging.channel_availability.email.surfaces.scheduling_public_booking',
            false,
        );
        config()->set(
            'messaging.channel_availability.sms.surfaces.scheduling_public_booking',
            false,
        );
        config()->set('scheduling.public', [
            'enabled' => true,
            'url' => rtrim($url, '/'),
            'host' => $host,
            'scheme' => $scheme,
            'availability_max_days' => 31,
            'reservation_rate_limit_per_minute' => 1000,
            'hold_review_rate_limit_per_minute' => 1000,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}