<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Actions\CreateBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssueBookableSlotOfferAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Jobs\ExpireBookingHoldsJob;
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
use LogicException;
use Tests\TestCase;

class BookableSlotOfferAndBookingHoldActionTest extends TestCase
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
        config()->set('scheduling.booking_holds.expiration_batch_size', 500);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_slot_offers_are_opaque_expiring_server_snapshots(): void
    {
        [$service, $host] = $this->hostedService(
            serviceAttributes: ['capacity' => 2],
            hostAttributes: ['capacity' => 2],
        );
        $this->absoluteAvailability($service, $host, capacity: 2);
        $slot = $this->slots($service, $host)[0];

        $offer = app(IssueBookableSlotOfferAction::class)->handle($slot);

        $this->assertTrue(Str::isUuid($offer->offer_id));
        $this->assertSame($service->id, $offer->bookable_service_id);
        $this->assertSame($host->id, $offer->scheduling_host_id);
        $this->assertTrue($offer->starts_at->equalTo($slot->startsAt));
        $this->assertTrue($offer->ends_at->equalTo($slot->endsAt));
        $this->assertSame(2, $offer->capacity);
        $this->assertSame(2, $offer->remaining_capacity);
        $this->assertSame(300, $offer->remainingSeconds());
        $this->assertTrue($offer->isActiveAt());
        $this->assertNull($offer->consumed_at);
        $this->assertSame($slot->sourceScopes, $offer->source_scopes);
        $this->assertSame($slot->sourceWindowIds, $offer->source_window_ids);
    }

    public function test_offer_issuance_rejects_a_stale_or_forged_slot(): void
    {
        [$service, $host] = $this->hostedService();
        $window = $this->absoluteAvailability($service, $host);
        $slot = $this->slots($service, $host)[0];

        $window->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer available');

        app(IssueBookableSlotOfferAction::class)->handle($slot);
    }

    public function test_hold_creation_consumes_the_offer_and_is_idempotent(): void
    {
        [$service, $host] = $this->hostedService([
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 15,
        ]);
        $this->absoluteAvailability($service, $host);
        $offer = $this->issueOffer($service, $host, 0);
        $action = app(CreateBookingHoldAction::class);

        $hold = $action->handle($offer->offer_id, 'booking-request-1');
        $replayed = $action->handle($offer->offer_id, 'booking-request-1');

        $this->assertSame($hold->id, $replayed->id);
        $this->assertTrue(Str::isUuid($hold->hold_id));
        $this->assertSame(BookingHold::STATUS_ACTIVE, $hold->status);
        $this->assertTrue($hold->occupancy_starts_at->equalTo($hold->starts_at->subMinutes(10)));
        $this->assertTrue($hold->occupancy_ends_at->equalTo($hold->ends_at->addMinutes(15)));
        $this->assertSame(600, $hold->remainingSeconds());
        $this->assertTrue($hold->isEffectivelyActive());

        $offer->refresh();
        $this->assertNotNull($offer->consumed_at);
        $this->assertFalse($offer->isActiveAt());
    }

    public function test_idempotency_keys_cannot_be_reused_for_another_offer(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $firstOffer = $this->issueOffer($service, $host, 0);
        $secondOffer = $this->issueOffer($service, $host, 1);
        $action = app(CreateBookingHoldAction::class);

        $action->handle($firstOffer->offer_id, 'shared-booking-key');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already used for another slot offer');

        $action->handle($secondOffer->offer_id, 'shared-booking-key');
    }

    public function test_expired_offers_and_inactive_services_cannot_create_holds(): void
    {
        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $expiredOffer = $this->issueOffer($service, $host, 0);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(6));

        try {
            app(CreateBookingHoldAction::class)->handle(
                $expiredOffer->offer_id,
                'expired-offer',
            );
            $this->fail('Expected an expired slot offer to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-07-27 12:00:00', 'UTC'),
        );

        $activeOffer = $this->issueOffer($service, $host, 1);
        $service->update(['status' => BookableService::STATUS_INACTIVE]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('service is no longer available');

        app(CreateBookingHoldAction::class)->handle(
            $activeOffer->offer_id,
            'inactive-service',
        );
    }

    public function test_competing_offers_respect_capacity_under_separate_holds(): void
    {
        [$service, $host] = $this->hostedService([
            'capacity' => 2,
        ], [
            'capacity' => 2,
        ], [
            'capacity_override' => 2,
        ]);
        $this->absoluteAvailability($service, $host, capacity: 2);
        $slot = $this->slots($service, $host)[0];
        $issue = app(IssueBookableSlotOfferAction::class);
        $offers = [
            $issue->handle($slot),
            $issue->handle($slot),
            $issue->handle($slot),
        ];
        $hold = app(CreateBookingHoldAction::class);

        $first = $hold->handle($offers[0]->offer_id, 'capacity-1');
        $second = $hold->handle($offers[1]->offer_id, 'capacity-2');

        $this->assertSame(BookingHold::STATUS_ACTIVE, $first->status);
        $this->assertSame(BookingHold::STATUS_ACTIVE, $second->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('available capacity');

        $hold->handle($offers[2]->offer_id, 'capacity-3');
    }

    public function test_active_hold_buffers_block_an_adjacent_slot(): void
    {
        [$service, $host] = $this->hostedService([
            'buffer_after_minutes' => 15,
        ]);
        $this->absoluteAvailability($service, $host);
        $firstOffer = $this->issueOffer($service, $host, 0);
        $adjacentOffer = $this->issueOffer($service, $host, 1);
        $hold = app(CreateBookingHoldAction::class);

        $hold->handle($firstOffer->offer_id, 'buffered-first');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('available capacity');

        $hold->handle($adjacentOffer->offer_id, 'buffered-adjacent');
    }

    public function test_expired_holds_stop_blocking_before_cleanup_and_job_marks_them_expired(): void
    {
        config()->set('scheduling.booking_holds.ttl_seconds', 60);

        [$service, $host] = $this->hostedService();
        $this->absoluteAvailability($service, $host);
        $slot = $this->slots($service, $host)[0];
        $issue = app(IssueBookableSlotOfferAction::class);
        $firstOffer = $issue->handle($slot);
        $secondOffer = $issue->handle($slot);
        $action = app(CreateBookingHoldAction::class);
        $firstHold = $action->handle($firstOffer->offer_id, 'expiring-hold');

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));

        $secondHold = $action->handle($secondOffer->offer_id, 'replacement-hold');

        $this->assertSame(BookingHold::STATUS_ACTIVE, $secondHold->status);
        $this->assertFalse($firstHold->fresh()->isEffectivelyActive());
        $this->assertSame(0, $firstHold->fresh()->remainingSeconds());

        $updated = app(ExpireBookingHoldsJob::class)->handle();

        $this->assertSame(1, $updated);
        $this->assertSame(
            BookingHold::STATUS_EXPIRED,
            $firstHold->fresh()->status,
        );
        $this->assertSame(
            BookingHold::STATUS_ACTIVE,
            $secondHold->fresh()->status,
        );
    }

    public function test_hold_creation_revalidates_changed_availability(): void
    {
        [$service, $host] = $this->hostedService();
        $window = $this->absoluteAvailability($service, $host);
        $offer = $this->issueOffer($service, $host, 0);

        $window->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('slot is no longer available');

        app(CreateBookingHoldAction::class)->handle(
            $offer->offer_id,
            'stale-availability',
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
        int $capacity = 1,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
            )
            ->forServiceAndHost($service, $host)
            ->create([
                'timezone' => 'UTC',
                'capacity' => $capacity,
            ]);
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function slots(
        BookableService $service,
        SchedulingHost $host,
    ): array {
        return app(FindBookableAvailabilityAction::class)->handle(
            new AvailabilitySearch(
                service: $service,
                startsAt: CarbonImmutable::parse('2026-07-28 09:00:00', 'UTC'),
                endsAt: CarbonImmutable::parse('2026-07-28 12:00:00', 'UTC'),
                host: $host,
                displayTimezone: 'UTC',
                evaluatedAt: CarbonImmutable::now('UTC'),
            ),
        );
    }

    private function issueOffer(
        BookableService $service,
        SchedulingHost $host,
        int $slotIndex,
    ): BookableSlotOffer {
        $slot = $this->slots($service, $host)[$slotIndex];

        return app(IssueBookableSlotOfferAction::class)->handle($slot);
    }
}