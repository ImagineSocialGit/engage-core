<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingBookingOffer;
use App\Modules\Scheduling\Models\SchedulingBookingOfferClaim;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class SchedulingBookingOfferReadService
{
    /** @return Collection<int, SchedulingBookingOffer> */
    public function forService(BookableService $service): Collection
    {
        return SchedulingBookingOffer::query()
            ->with(['conditions', 'rewards.actions'])
            ->where('bookable_service_id', $service->getKey())
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }

    public function byCode(
        BookableService $service,
        string $code,
    ): ?SchedulingBookingOffer {
        $code = SchedulingBookingOffer::normalizeCode($code);

        if ($code === '') {
            return null;
        }

        $offer = SchedulingBookingOffer::query()
            ->with(['conditions', 'rewards.actions'])
            ->where('bookable_service_id', $service->getKey())
            ->where('code', $code)
            ->first();

        return $offer instanceof SchedulingBookingOffer ? $offer : null;
    }

    public function activeByCode(
        BookableService $service,
        string $code,
        ?CarbonInterface $at = null,
    ): ?SchedulingBookingOffer {
        $offer = $this->byCode($service, $code);

        if (! $offer instanceof SchedulingBookingOffer || ! $offer->isOpenAt($at)) {
            return null;
        }

        return $offer;
    }

    /** @return array<string, mixed>|null */
    public function publicCodeSummary(
        BookableService $service,
        string $code,
        ?CarbonInterface $at = null,
    ): ?array {
        $offer = $this->byCode($service, $code);

        if (! $offer instanceof SchedulingBookingOffer) {
            return null;
        }

        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return [
            'id' => (int) $offer->getKey(),
            'code' => $offer->code,
            'name' => $offer->name,
            'open' => $offer->isOpenAt($at),
            'status_message' => $this->statusMessage($offer, $at),
            'condition_count' => $offer->conditions->count(),
            'rewards' => $offer->rewards
                ->map(fn ($reward): array => [
                    'name' => $reward->name,
                    'max_claim_number' => (int) $reward->max_claim_number,
                ])
                ->values()
                ->all(),
        ];
    }

    public function claimedCount(
        SchedulingBookingOffer $offer,
        string $scopeKey,
    ): int {
        return SchedulingBookingOfferClaim::query()
            ->where('scheduling_booking_offer_id', $offer->getKey())
            ->where('qualification_scope_key', $scopeKey)
            ->count();
    }

    private function statusMessage(
        SchedulingBookingOffer $offer,
        CarbonImmutable $now,
    ): string {
        if (! $offer->isActive()) {
            return 'This offer code is not active.';
        }

        if ($offer->starts_at !== null
            && CarbonImmutable::instance($offer->starts_at)->utc()->greaterThan($now)
        ) {
            return 'This offer code is not open yet.';
        }

        if ($offer->ends_at !== null
            && CarbonImmutable::instance($offer->ends_at)->utc()->lessThanOrEqualTo($now)
        ) {
            return 'This offer code has ended.';
        }

        return $offer->conditions->isEmpty()
            ? 'This offer will be applied when the booking is completed.'
            : 'Eligibility will be checked when the booking is completed.';
    }
}