<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Scheduling\Actions\ClaimSchedulingBookingOfferAction;
use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\IssuePublicBookingSlotOfferAction;
use App\Modules\Scheduling\Contracts\BookingEligibilityProvider;
use App\Modules\Scheduling\Contracts\BookingOfferRewardActionHandler;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\BookingEligibilityIdentity;
use App\Modules\Scheduling\Exceptions\BookingOfferEligibilityException;
use App\Modules\Scheduling\Exceptions\SchedulingBookingOfferExhaustedException;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingBookingOffer;
use App\Modules\Scheduling\Models\SchedulingBookingOfferClaim;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Services\BookingEligibilityProviderRegistry;
use App\Modules\Scheduling\Services\BookingOfferRewardActionHandlerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingBookingOfferRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_crm_can_author_multiple_code_based_offers_for_one_appointment_type(): void
    {
        $this->registerEligibilityProvider();
        $user = User::factory()->create();
        $service = BookableService::factory()->create([
            'name' => 'Webinar Consultation',
        ]);

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.services.offers.index', $service))
            ->assertOk()
            ->assertSee('data-scheduling-booking-offers-workspace="'.$service->id.'"', false);

        $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.services.offers.store', $service), [
                'enabled' => '1',
                'code' => 'freeva',
                'name' => 'Webinar consultation',
                'eligibility_provider' => TestBookingEligibilityProvider::KEY,
                'eligibility_option' => 'series:7:latest_started',
                'claim_limit' => 30,
                'rewards' => [
                    [
                        'name' => 'Free consultation',
                        'max_claim_number' => 30,
                        'contact_tag' => 'free-consultation',
                    ],
                    [
                        'name' => 'Free appraisal certificate',
                        'max_claim_number' => 10,
                        'contact_tag' => 'free-appraisal',
                    ],
                ],
            ])
            ->assertRedirect(route(
                'crm.scheduling.configuration.services.offers.index',
                $service,
            ));

        $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.services.offers.store', $service), [
                'enabled' => '1',
                'code' => 'FRIEND25',
                'name' => 'Referral offer',
            ])
            ->assertRedirect(route(
                'crm.scheduling.configuration.services.offers.index',
                $service,
            ));

        $this->assertSame(2, SchedulingBookingOffer::query()->count());

        $webinarOffer = SchedulingBookingOffer::query()
            ->where('code', 'FREEVA')
            ->with(['conditions', 'rewards.actions'])
            ->sole();
        $referralOffer = SchedulingBookingOffer::query()
            ->where('code', 'FRIEND25')
            ->with('conditions')
            ->sole();

        $this->assertSame(30, $webinarOffer->claim_limit);
        $this->assertSame(1, $webinarOffer->conditions->count());
        $this->assertSame(TestBookingEligibilityProvider::KEY, $webinarOffer->conditions->first()?->provider);
        $this->assertSame(
            ['series_id' => 7, 'occurrence' => 'latest_started'],
            $webinarOffer->conditions->first()?->criteria,
        );
        $this->assertSame('contact_tag', $webinarOffer->rewards->first()?->actions->first()?->provider);
        $this->assertSame(0, $referralOffer->conditions->count());
    }

    public function test_offer_claim_numbers_reset_when_the_qualification_scope_changes(): void
    {
        $eligibility = $this->registerEligibilityProvider();
        $this->registerRewardHandler();
        $service = BookableService::factory()->create();
        $offer = $this->limitedOffer($service, claimLimit: 2);

        $first = Contact::factory()->create(['email' => 'first@example.test']);
        $second = Contact::factory()->create(['email' => 'second@example.test']);
        $third = Contact::factory()->create(['email' => 'third@example.test']);
        $eligibility->allow($first);
        $eligibility->allow($second);
        $eligibility->allow($third);

        $firstClaim = $this->claim($offer, $service, $first);
        $secondClaim = $this->claim($offer, $service, $second);

        $this->assertSame(1, $firstClaim?->claim_number);
        $this->assertSame(2, $secondClaim?->claim_number);
        $this->assertDatabaseHas('contact_tags', [
            'contact_id' => $first->getKey(),
            'tag' => 'free-appraisal',
        ]);
        $this->assertDatabaseHas('contact_tags', [
            'contact_id' => $first->getKey(),
            'tag' => 'free-consultation',
        ]);
        $this->assertDatabaseMissing('contact_tags', [
            'contact_id' => $second->getKey(),
            'tag' => 'free-appraisal',
        ]);

        try {
            $this->claim($offer, $service, $third);
            $this->fail('Expected the first qualification scope to be exhausted.');
        } catch (SchedulingBookingOfferExhaustedException) {
            $this->assertSame(2, SchedulingBookingOfferClaim::query()->count());
        }

        $eligibility->useScope('webinar:8');
        $nextScopeClaim = $this->claim($offer, $service, $third);

        $this->assertSame(1, $nextScopeClaim?->claim_number);
        $this->assertSame(
            TestBookingEligibilityProvider::KEY.':webinar:8',
            $nextScopeClaim?->qualification_scope_key,
        );
    }

    public function test_public_booking_snapshots_only_an_explicit_offer_code_into_the_hold(): void
    {
        CarbonImmutable::setTestNow('2026-09-16 12:00:00 UTC');
        $service = BookableService::factory()->create([
            'key' => 'offer-code-consultation',
            'status' => BookableService::STATUS_ACTIVE,
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
        SchedulingAvailabilityWindow::factory()
            ->serviceWide($service)
            ->absolute(
                CarbonImmutable::parse('2026-09-17 09:00:00 UTC'),
                CarbonImmutable::parse('2026-09-17 10:00:00 UTC'),
            )
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
        $bookingOffer = SchedulingBookingOffer::query()->create([
            'bookable_service_id' => $service->getKey(),
            'code' => 'FREEVA',
            'name' => 'Webinar consultation',
            'status' => SchedulingBookingOffer::STATUS_ACTIVE,
            'claim_limit' => 30,
        ]);

        $ordinarySlotOffer = app(IssuePublicBookingSlotOfferAction::class)->handle(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-09-17 09:00:00 UTC'),
        );

        $this->assertNull(data_get($ordinarySlotOffer->meta, 'booking_offer'));

        $codedSlotOffer = app(IssuePublicBookingSlotOfferAction::class)->handle(
            service: $service,
            startsAt: CarbonImmutable::parse('2026-09-17 09:00:00 UTC'),
            bookingOfferCode: 'freeva',
        );

        $this->assertSame(
            (int) $bookingOffer->getKey(),
            (int) data_get($codedSlotOffer->meta, 'booking_offer.id'),
        );
        $this->assertSame(
            'FREEVA',
            data_get($codedSlotOffer->meta, 'booking_offer.code'),
        );

        $hold = app(CreateBookingHoldAction::class)->handle(
            offerId: $codedSlotOffer->offer_id,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->assertSame(
            (int) $bookingOffer->getKey(),
            (int) data_get($hold->meta, 'booking_offer.id'),
        );
        $this->assertSame('FREEVA', data_get($hold->meta, 'booking_offer.code'));
    }

    public function test_active_offers_do_not_apply_when_the_booking_hold_has_no_offer_code_selection(): void
    {
        $this->registerEligibilityProvider();
        $this->registerRewardHandler();
        $service = BookableService::factory()->create();
        $offer = $this->limitedOffer($service, claimLimit: 30);
        $contact = Contact::factory()->create(['email' => 'normal@example.test']);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $hold = new BookingHold(['meta' => []]);

        $claim = app(ClaimSchedulingBookingOfferAction::class)->handle(
            appointment: $appointment,
            service: $service,
            hold: $hold,
            booking: new AppointmentBookingData(
                contact: $contact,
                email: $contact->email,
            ),
        );

        $this->assertNull($claim);
        $this->assertSame(0, SchedulingBookingOfferClaim::query()->count());
        $this->assertSame(1, SchedulingBookingOffer::query()->whereKey($offer->getKey())->count());
    }

    public function test_unqualified_contact_cannot_claim_selected_offer(): void
    {
        $this->registerEligibilityProvider();
        $this->registerRewardHandler();
        $service = BookableService::factory()->create();
        $offer = $this->limitedOffer($service, claimLimit: 30);
        $contact = Contact::factory()->create(['email' => 'not-registered@example.test']);

        try {
            $this->claim($offer, $service, $contact);
            $this->fail('Expected an eligibility exception.');
        } catch (BookingOfferEligibilityException) {
            $this->assertSame(0, SchedulingBookingOfferClaim::query()->count());
        }
    }

    private function limitedOffer(
        BookableService $service,
        int $claimLimit,
    ): SchedulingBookingOffer {
        $offer = SchedulingBookingOffer::query()->create([
            'bookable_service_id' => $service->getKey(),
            'code' => 'FREEVA',
            'name' => 'Limited webinar offer',
            'status' => SchedulingBookingOffer::STATUS_ACTIVE,
            'claim_limit' => $claimLimit,
        ]);
        $offer->conditions()->create([
            'provider' => TestBookingEligibilityProvider::KEY,
            'criteria' => [
                'series_id' => 7,
                'occurrence' => 'latest_started',
            ],
            'sort_order' => 0,
        ]);
        $consultation = $offer->rewards()->create([
            'name' => 'Consultation',
            'max_claim_number' => $claimLimit,
            'sort_order' => 0,
        ]);
        $consultation->actions()->create([
            'provider' => TestBookingOfferRewardActionHandler::KEY,
            'payload' => ['tag' => 'free-consultation'],
            'sort_order' => 0,
        ]);
        $appraisal = $offer->rewards()->create([
            'name' => 'Appraisal',
            'max_claim_number' => 1,
            'sort_order' => 1,
        ]);
        $appraisal->actions()->create([
            'provider' => TestBookingOfferRewardActionHandler::KEY,
            'payload' => ['tag' => 'free-appraisal'],
            'sort_order' => 0,
        ]);

        return $offer->refresh();
    }

    private function claim(
        SchedulingBookingOffer $offer,
        BookableService $service,
        Contact $contact,
    ): ?SchedulingBookingOfferClaim {
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $hold = new BookingHold([
            'meta' => [
                'booking_offer' => [
                    'id' => $offer->getKey(),
                    'code' => $offer->code,
                    'applied_at' => CarbonImmutable::parse('2026-09-16 12:00:00 UTC')->toISOString(),
                ],
            ],
        ]);

        return app(ClaimSchedulingBookingOfferAction::class)->handle(
            appointment: $appointment,
            service: $service,
            hold: $hold,
            booking: new AppointmentBookingData(
                contact: $contact,
                email: $contact->email,
            ),
        );
    }

    private function registerEligibilityProvider(): TestBookingEligibilityProvider
    {
        $provider = new TestBookingEligibilityProvider();
        $this->app->instance(TestBookingEligibilityProvider::class, $provider);
        $this->app->tag(
            [TestBookingEligibilityProvider::class],
            BookingEligibilityProviderRegistry::TAG,
        );

        return $provider;
    }

    private function registerRewardHandler(): void
    {
        $this->app->tag(
            [TestBookingOfferRewardActionHandler::class],
            BookingOfferRewardActionHandlerRegistry::TAG,
        );
    }
}

final class TestBookingEligibilityProvider implements BookingEligibilityProvider
{
    public const KEY = 'test_registrant';

    /** @var array<string, int> */
    private array $contactsByEmail = [];
    private string $scopeKey = 'webinar:7';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Test registrant';
    }

    public function options(): array
    {
        return [[
            'value' => 'series:7:latest_started',
            'label' => 'Test webinar series · most recent started webinar',
            'group' => 'Webinar registration',
            'criteria' => [
                'series_id' => 7,
                'occurrence' => 'latest_started',
            ],
        ]];
    }

    public function resolve(
        array $criteria,
        string $email,
        ?\Carbon\CarbonInterface $evaluatedAt = null,
    ): ?BookingEligibilityIdentity {
        if ($criteria != ['series_id' => 7, 'occurrence' => 'latest_started']) {
            return null;
        }

        $contactId = $this->contactsByEmail[strtolower(trim($email))] ?? null;

        return is_int($contactId)
            ? new BookingEligibilityIdentity(
                contactId: $contactId,
                scopeKey: $this->scopeKey,
                meta: ['criteria' => $criteria],
            )
            : null;
    }

    public function allow(Contact $contact): void
    {
        $this->contactsByEmail[strtolower((string) $contact->email)] = (int) $contact->getKey();
    }

    public function useScope(string $scopeKey): void
    {
        $this->scopeKey = $scopeKey;
    }
}

final class TestBookingOfferRewardActionHandler implements BookingOfferRewardActionHandler
{
    public const KEY = 'test_contact_tag';

    public function key(): string
    {
        return self::KEY;
    }

    public function apply(
        Contact $contact,
        Appointment $appointment,
        array $payload,
    ): void {
        ContactTag::query()->firstOrCreate([
            'contact_id' => $contact->getKey(),
            'tag' => (string) ($payload['tag'] ?? ''),
        ]);
    }
}