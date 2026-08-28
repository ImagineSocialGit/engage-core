<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingAppointmentCommunications;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicBookingAppointmentCommunicationsConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-28 17:00:00 UTC');

        foreach (['email', 'sms'] as $channel) {
            config()->set("messaging.channel_availability.{$channel}.runtime_supported", true);
            config()->set("messaging.channel_availability.{$channel}.surfaces.scheduling_appointments", true);
            config()->set("messaging.channel_availability.{$channel}.purpose_scopes", ['*' => true]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_booking_disclosure_grants_transactional_email_and_matching_sms_without_marketing(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => '+15555550123',
        ]);
        $appointment = $this->publicAppointment($contact);
        AppointmentAttendee::factory()->forContact($contact)->create([
            'appointment_id' => $appointment->getKey(),
            'phone' => '(555) 555-0123',
        ]);

        app(MessagingAppointmentCommunications::class)->publicBookingCompleted(
            appointment: $appointment,
            sourceIp: '203.0.113.10',
            userAgent: 'Engage Scheduling Test',
        );

        $consents = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->orderBy('channel')
            ->get();

        $this->assertCount(2, $consents);
        $this->assertEqualsCanonicalizing(
            [MessageChannel::Email, MessageChannel::Sms],
            $consents->pluck('channel')->all(),
        );

        foreach ($consents as $consent) {
            $this->assertSame(MessagePurpose::Transactional, $consent->purpose);
            $this->assertSame('scheduling_appointments', $consent->scope);
            $this->assertSame('scheduling_public_booking', $consent->source);
            $this->assertSame('203.0.113.10', $consent->ip_address);
            $this->assertSame('appointment-communications-v2', data_get($consent->meta, 'disclosure.version'));
        }

        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->getKey(),
            'purpose' => MessagePurpose::Marketing->value,
        ]);
    }

    public function test_public_booking_does_not_grant_sms_to_an_existing_contact_phone_that_did_not_match_booking_snapshot(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'existing@example.test',
            'phone' => '+15555550111',
        ]);
        $appointment = $this->publicAppointment($contact);
        AppointmentAttendee::factory()->forContact($contact)->create([
            'appointment_id' => $appointment->getKey(),
            'phone' => '+15555550222',
        ]);

        app(MessagingAppointmentCommunications::class)->publicBookingCompleted($appointment);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'scheduling_appointments',
        ]);
        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Transactional->value,
        ]);
    }

    private function publicAppointment(Contact $contact): Appointment
    {
        return Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'source' => 'public_booking',
            'meta' => [
                'public_booking_disclosure' => [
                    'key' => 'scheduling.public_booking.communications',
                    'version' => 'appointment-communications-v2',
                    'text_hash' => hash('sha256', 'test disclosure'),
                    'accepted_at' => CarbonImmutable::now('UTC')->toISOString(),
                ],
            ],
        ]);
    }
}