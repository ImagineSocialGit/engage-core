<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Services\PublicBookingDestinationVerificationService;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingSchedulingDestinationVerificationTransport;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingDestinationVerificationRecipientGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingDestinationVerificationMessagingBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-25 16:00:00 UTC');

        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.email.provider_enabled', true);
        config()->set(
            'messaging.channel_availability.email.surfaces.scheduling_public_booking',
            true,
        );
        config()->set('messaging.channel_availability.email.purpose_scopes', ['*' => true]);
        config()->set('messaging.channel_availability.sms.provider_enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_messaging_bridge_schedules_transactional_verification_without_contact_or_consent_creation(): void
    {
        $offer = $this->publicOffer();
        $transport = app(MessagingSchedulingDestinationVerificationTransport::class);

        $this->assertSame(
            ['email'],
            $transport->availableChannels(
                surface: PublicBookingDestinationVerificationService::SURFACE,
                purpose: PublicBookingDestinationVerificationService::PURPOSE,
                scope: PublicBookingDestinationVerificationService::SCOPE,
            ),
        );

        $transport->send(
            recipient: $offer,
            surface: PublicBookingDestinationVerificationService::SURFACE,
            channel: 'email',
            purpose: PublicBookingDestinationVerificationService::PURPOSE,
            scope: PublicBookingDestinationVerificationService::SCOPE,
            destination: 'person@example.test',
            code: '123456',
            dedupeKey: 'test:scheduling:verification:email',
            sourceIp: '203.0.113.10',
        );

        $message = ScheduledMessage::query()->sole();

        $this->assertSame($offer->getMorphClass(), $message->recipient_type);
        $this->assertSame((int) $offer->getKey(), (int) $message->recipient_id);
        $this->assertSame('email', $message->channel);
        $this->assertSame('transactional', $message->purpose);
        $this->assertSame('scheduling_public_booking', $message->scope);
        $this->assertSame(
            MessagingSchedulingDestinationVerificationTransport::MESSAGE_TYPE,
            $message->message_type,
        );
        $this->assertSame('person@example.test', $message->payload['to']);
        $this->assertSame(
            PublicBookingDestinationVerificationService::SURFACE,
            data_get($message->meta, 'surface'),
        );

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('message_consents', 0);
        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_recipient_gate_accepts_only_active_public_transactional_verification_offer_messages(): void
    {
        $offer = $this->publicOffer();
        $gate = app(SchedulingDestinationVerificationRecipientGate::class);
        $context = [
            'purpose' => 'transactional',
            'scope' => 'scheduling_public_booking',
            'meta' => [
                'surface' => PublicBookingDestinationVerificationService::SURFACE,
            ],
        ];

        $this->assertNull($gate->denialReason(
            recipient: $offer,
            channel: 'email',
            type: MessagingSchedulingDestinationVerificationTransport::MESSAGE_TYPE,
            context: $context,
        ));

        $this->assertSame(
            'Destination verification message identity is invalid.',
            $gate->denialReason(
                recipient: $offer,
                channel: 'email',
                type: MessagingSchedulingDestinationVerificationTransport::MESSAGE_TYPE,
                context: array_replace($context, ['purpose' => 'marketing']),
            ),
        );
    }

    private function publicOffer(): BookableSlotOffer
    {
        $service = BookableService::factory()->create([
            'key' => 'verification-bridge-'.Str::lower(Str::random(8)),
            'status' => BookableService::STATUS_ACTIVE,
            'is_public' => true,
            'timezone' => 'UTC',
        ]);
        $now = CarbonImmutable::now('UTC');

        return BookableSlotOffer::query()->create([
            'offer_id' => (string) Str::uuid(),
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => null,
            'reschedule_appointment_id' => null,
            'starts_at' => $now->addDay(),
            'ends_at' => $now->addDay()->addHour(),
            'display_timezone' => 'UTC',
            'capacity' => 1,
            'remaining_capacity' => 1,
            'location_type' => null,
            'location_details' => null,
            'source_scopes' => [],
            'source_window_ids' => [],
            'issued_at' => $now,
            'expires_at' => $now->addMinutes(10),
            'consumed_at' => null,
            'meta' => [
                'public_booking' => true,
            ],
        ]);
    }
}