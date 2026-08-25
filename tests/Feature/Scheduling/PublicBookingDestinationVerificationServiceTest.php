<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Services\PublicBookingDestinationVerificationService;
use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use App\Support\DestinationVerification\UnavailableDestinationVerificationTransport;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingDestinationVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow('2026-08-25 16:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_verification_issues_hashed_ephemeral_challenge_and_single_use_offer_bound_proof(): void
    {
        $transport = new RecordingDestinationVerificationTransport(['email', 'sms']);
        $service = new PublicBookingDestinationVerificationService($transport);
        $offer = $this->publicOffer();

        $challenge = $service->issue(
            offer: $offer,
            sessionId: 'booking-session-a',
            channel: 'email',
            destination: ' PERSON@EXAMPLE.TEST ',
            sourceIp: '203.0.113.10',
        );

        $this->assertSame('email', $challenge->channel);
        $this->assertSame('p***@example.test', $challenge->maskedDestination);
        $this->assertCount(1, $transport->sent);
        $this->assertSame('person@example.test', $transport->sent[0]['destination']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $transport->sent[0]['code']);

        $state = Cache::get(
            'scheduling:destination_verification:challenge:'.$challenge->challengeId,
        );

        $this->assertIsArray($state);
        $this->assertArrayNotHasKey('code', $state);
        $this->assertNotSame($transport->sent[0]['code'], $state['code_hash']);
        $this->assertSame(
            (string) $offer->offer_id,
            $state['offer_id'],
        );

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);

        try {
            $service->verify(
                offer: $offer,
                sessionId: 'booking-session-a',
                challengeId: $challenge->challengeId,
                code: '000000',
            );

            $this->fail('An incorrect verification code should fail.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Destination verification code is invalid.',
                $exception->getMessage(),
            );
        }

        $proof = $service->verify(
            offer: $offer,
            sessionId: 'booking-session-a',
            challengeId: $challenge->challengeId,
            code: $transport->sent[0]['code'],
        );

        $this->assertFalse($service->hasValidProof(
            offer: $offer,
            sessionId: 'booking-session-b',
            proofToken: $proof->token,
        ));
        $this->assertTrue($service->hasValidProof(
            offer: $offer,
            sessionId: 'booking-session-a',
            proofToken: $proof->token,
        ));
        $this->assertFalse($service->consumeProof(
            offer: $offer,
            sessionId: 'booking-session-b',
            proofToken: $proof->token,
        ));
        $this->assertTrue($service->consumeProof(
            offer: $offer,
            sessionId: 'booking-session-a',
            proofToken: $proof->token,
        ));
        $this->assertFalse($service->consumeProof(
            offer: $offer,
            sessionId: 'booking-session-a',
            proofToken: $proof->token,
        ));

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_resend_reuses_the_active_challenge_code_without_extending_offer_bounded_expiration(): void
    {
        config()->set(
            'scheduling.public.destination_verification.resend_cooldown_seconds',
            5,
        );
        config()->set(
            'scheduling.public.destination_verification.max_sends_per_challenge',
            2,
        );

        $transport = new RecordingDestinationVerificationTransport(['sms']);
        $service = new PublicBookingDestinationVerificationService($transport);
        $offer = $this->publicOffer(expiresInSeconds: 90);

        $challenge = $service->issue(
            offer: $offer,
            sessionId: 'booking-session-resend',
            channel: 'sms',
            destination: '(555) 555-0123',
        );
        $originalExpiration = $challenge->expiresAt;

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(6));

        $resent = $service->resend(
            offer: $offer,
            sessionId: 'booking-session-resend',
            challengeId: $challenge->challengeId,
        );

        $this->assertCount(2, $transport->sent);
        $this->assertSame($transport->sent[0]['code'], $transport->sent[1]['code']);
        $this->assertTrue($resent->expiresAt->equalTo($originalExpiration));

        $proof = $service->verify(
            offer: $offer,
            sessionId: 'booking-session-resend',
            challengeId: $challenge->challengeId,
            code: $transport->sent[1]['code'],
        );

        $this->assertTrue($service->consumeProof(
            offer: $offer,
            sessionId: 'booking-session-resend',
            proofToken: $proof->token,
        ));
    }

    public function test_scheduling_degrades_to_no_verification_channels_when_transport_is_unavailable(): void
    {
        $service = new PublicBookingDestinationVerificationService(
            new UnavailableDestinationVerificationTransport(),
        );
        $offer = $this->publicOffer();

        $this->assertEquals([], $service->availableChannels());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Destination verification channel [email] is unavailable.',
        );

        $service->issue(
            offer: $offer,
            sessionId: 'booking-session-no-messaging',
            channel: 'email',
            destination: 'person@example.test',
        );
    }

    private function publicOffer(int $expiresInSeconds = 600): BookableSlotOffer
    {
        $service = BookableService::factory()->create([
            'key' => 'verification-'.Str::lower(Str::random(10)),
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
            'expires_at' => $now->addSeconds($expiresInSeconds),
            'consumed_at' => null,
            'meta' => [
                'public_booking' => true,
            ],
        ]);
    }
}

final class RecordingDestinationVerificationTransport implements DestinationVerificationTransport
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    /** @param array<int, string> $channels */
    public function __construct(
        private readonly array $channels,
    ) {}

    public function availableChannels(
        string $surface,
        string $purpose,
        string $scope,
    ): array {
        return $this->channels;
    }

    public function normalizeDestination(
        string $channel,
        string $destination,
    ): ?string {
        $destination = trim($destination);

        if ($channel === 'email') {
            $destination = strtolower($destination);

            return filter_var($destination, FILTER_VALIDATE_EMAIL) !== false
                ? $destination
                : null;
        }

        if ($channel === 'sms') {
            $digits = preg_replace('/\D+/', '', $destination) ?? '';

            return strlen($digits) === 10
                ? '+1'.$digits
                : ($digits !== '' ? '+'.$digits : null);
        }

        return null;
    }

    public function send(
        Model $recipient,
        string $surface,
        string $channel,
        string $purpose,
        string $scope,
        string $destination,
        string $code,
        string $dedupeKey,
        ?string $sourceIp = null,
    ): void {
        $this->sent[] = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'surface' => $surface,
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
            'destination' => $destination,
            'code' => $code,
            'dedupe_key' => $dedupeKey,
            'source_ip' => $sourceIp,
        ];
    }
}