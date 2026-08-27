<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use App\Support\DestinationVerification\UnavailableDestinationVerificationTransport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PublicBookingDestinationVerificationEnforcementTest extends TestCase
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

    public function test_public_offer_requires_server_owned_verification_before_hold_when_transport_is_available(): void
    {
        config()->set(
            'scheduling.public.destination_verification.resend_cooldown_seconds',
            5,
        );

        $transport = $this->recordingTransport(['email']);
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService('verified-consultation');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-26 09:00:00 UTC',
            endsAt: '2026-08-26 10:00:00 UTC',
        );
        $offer = $this->issueOffer($service, '2026-08-26T09:00:00.000000Z');
        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->get($offerUrl)
            ->assertOk()
            ->assertSee('data-destination-verification="required"', false)
            ->assertDontSee('action="/offers/'.$offer->offer_id.'/hold"', false);

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertNull($offer->refresh()->consumed_at);

        $this->post($offerUrl.'/verification', [
            'channel' => 'email',
            'destination' => ' PERSON@EXAMPLE.TEST ',
        ])->assertRedirect($offerUrl);

        $this->assertCount(1, $transport->sent);
        $this->assertSame('person@example.test', $transport->sent[0]['destination']);

        $this->get($offerUrl)
            ->assertOk()
            ->assertSee('data-destination-verification="challenge"', false)
            ->assertDontSee('action="/offers/'.$offer->offer_id.'/hold"', false);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(6));

        $this->post($offerUrl.'/verification/resend')
            ->assertRedirect($offerUrl)
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $transport->sent);
        $this->assertSame($transport->sent[0]['code'], $transport->sent[1]['code']);

        $verification = $this->post($offerUrl.'/verification/verify', [
            'code' => $transport->sent[1]['code'],
        ]);

        $hold = BookingHold::query()->sole();

        $verification
            ->assertRedirect('https://schedule.test/book/'.$hold->hold_id)
            ->assertSessionHasNoErrors();
        $this->assertNotNull($offer->refresh()->consumed_at);

        $this->get('https://schedule.test/book/'.$hold->hold_id)
            ->assertOk()
            ->assertSee('"verificationCompletedChannel":"email"', false)
            ->assertSee('value="person@example.test"', false)
            ->assertSee('Verified email')
            ->assertSee('name="email"', false)
            ->assertSee('readonly', false)
            ->assertDontSee('name="name"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false);

        $this->from('https://schedule.test/book/'.$hold->hold_id)
            ->post('https://schedule.test/book/'.$hold->hold_id, [
                'first_name' => 'Changed',
                'last_name' => 'Address',
                'email' => 'changed@example.test',
            ])
            ->assertRedirect('https://schedule.test/book/'.$hold->hold_id)
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('appointments', 0);

        $this->get($offerUrl)
            ->assertRedirect('https://schedule.test/book/'.$hold->hold_id);
        $this->assertDatabaseCount('booking_holds', 1);
    }

    public function test_public_requests_reject_browser_authored_verification_authority(): void
    {
        $this->recordingTransport(['email']);
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService('forged-verification');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-26 09:00:00 UTC',
            endsAt: '2026-08-26 10:00:00 UTC',
        );
        $offer = $this->issueOffer($service, '2026-08-26T09:00:00.000000Z');
        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
                'challenge_id' => (string) Str::uuid(),
                'verification_proof_token' => Str::random(64),
                'verified' => true,
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors([
                'challenge_id',
                'verification_proof_token',
                'verified',
            ]);

        $this->from($offerUrl)
            ->post($offerUrl.'/verification', [
                'channel' => 'email',
                'destination' => 'person@example.test',
                'challenge_id' => (string) Str::uuid(),
                'proof_token' => Str::random(64),
                'verified' => true,
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors([
                'challenge_id',
                'proof_token',
                'verified',
            ]);

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertNull($offer->refresh()->consumed_at);
    }

    public function test_verified_offer_is_still_revalidated_before_capacity_is_consumed(): void
    {
        $transport = $this->recordingTransport(['email']);
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService('verified-capacity-race');
        $host = SchedulingHost::factory()->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-26 09:00:00 UTC',
            endsAt: '2026-08-26 10:00:00 UTC',
        );
        $offer = $this->issueOffer($service, '2026-08-26T09:00:00.000000Z');
        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->post($offerUrl.'/verification', [
            'channel' => 'email',
            'destination' => 'person@example.test',
        ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasNoErrors();

        $this->get($offerUrl)
            ->assertOk()
            ->assertSee('data-destination-verification="challenge"', false);

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'starts_at' => CarbonImmutable::parse('2026-08-26 09:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-26 10:00:00 UTC'),
            'timezone' => 'UTC',
        ]);

        $this->from($offerUrl)
            ->post($offerUrl.'/verification/verify', [
                'code' => $transport->sent[0]['code'],
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertNull($offer->refresh()->consumed_at);
    }

    public function test_expired_offer_cannot_complete_verification_or_create_a_hold(): void
    {
        config()->set('scheduling.slot_offers.ttl_seconds', 60);
        $transport = $this->recordingTransport(['email']);
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService('expired-verification');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-26 09:00:00 UTC',
            endsAt: '2026-08-26 10:00:00 UTC',
        );
        $offer = $this->issueOffer($service, '2026-08-26T09:00:00.000000Z');
        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->post($offerUrl.'/verification', [
            'channel' => 'email',
            'destination' => 'person@example.test',
        ])->assertRedirect($offerUrl);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));

        $this->from($offerUrl)
            ->post($offerUrl.'/verification/verify', [
                'code' => $transport->sent[0]['code'],
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('code');

        $this->from($offerUrl)
            ->post($offerUrl.'/hold', [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect($offerUrl)
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('booking_holds', 0);
        $this->assertNull($offer->refresh()->consumed_at);
    }

    public function test_public_booking_keeps_the_direct_hold_path_when_verification_transport_is_unavailable(): void
    {
        $this->app->instance(
            DestinationVerificationTransport::class,
            new UnavailableDestinationVerificationTransport(),
        );
        $this->registerPublicSurface('https://schedule.test');
        $service = $this->publicService('no-messaging-booking');
        $this->absoluteAvailability(
            service: $service,
            startsAt: '2026-08-26 09:00:00 UTC',
            endsAt: '2026-08-26 10:00:00 UTC',
        );
        $offer = $this->issueOffer($service, '2026-08-26T09:00:00.000000Z');
        $offerUrl = 'https://schedule.test/offers/'.$offer->offer_id;

        $this->get($offerUrl)
            ->assertOk()
            ->assertDontSee('data-destination-verification="required"', false)
            ->assertSee('action="/offers/'.$offer->offer_id.'/hold"', false);

        $response = $this->post($offerUrl.'/hold', [
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('booking_holds', 1);
    }

    private function recordingTransport(array $channels): RecordingPublicBookingDestinationVerificationTransport
    {
        $transport = new RecordingPublicBookingDestinationVerificationTransport($channels);

        $this->app->instance(
            DestinationVerificationTransport::class,
            $transport,
        );

        return $transport;
    }

    private function publicService(string $key): BookableService
    {
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
            'is_public' => true,
        ]);
    }

    private function absoluteAvailability(
        BookableService $service,
        string $startsAt,
        string $endsAt,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse($startsAt),
                CarbonImmutable::parse($endsAt),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }

    private function issueOffer(
        BookableService $service,
        string $startsAt,
    ): BookableSlotOffer {
        $this->post(
            'https://schedule.test/services/'.$service->key.'/offers',
            ['starts_at' => $startsAt],
        )->assertRedirect();

        return BookableSlotOffer::query()
            ->where('bookable_service_id', $service->id)
            ->latest('id')
            ->firstOrFail();
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
        config()->set('scheduling.public.enabled', true);
        config()->set('scheduling.public.url', rtrim($url, '/'));
        config()->set('scheduling.public.host', $host);
        config()->set('scheduling.public.scheme', $scheme);
        config()->set('scheduling.public.availability_max_days', 31);
        config()->set('scheduling.public.reservation_rate_limit_per_minute', 50);
        config()->set('scheduling.public.hold_review_rate_limit_per_minute', 60);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}

final class RecordingPublicBookingDestinationVerificationTransport implements DestinationVerificationTransport
{
    /** @var array<int, string> */
    private array $channels;

    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    /** @param array<int, string> $channels */
    public function __construct(array $channels)
    {
        $this->channels = array_values($channels);
    }

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
        $channel = strtolower(trim($channel));
        $destination = trim($destination);

        if ($channel === 'email') {
            $destination = strtolower($destination);

            return filter_var($destination, FILTER_VALIDATE_EMAIL) !== false
                ? $destination
                : null;
        }

        if ($channel === 'sms') {
            $digits = preg_replace('/\D+/', '', $destination) ?? '';

            return $digits !== '' ? '+'.$digits : null;
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
        if (! in_array($channel, $this->channels, true)) {
            throw new InvalidArgumentException('Unavailable test verification channel.');
        }

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