<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Services\SchedulingDurationResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class CreatePublicBookingHoldAction
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly IssueBookableSlotOfferAction $issueSlotOffer,
        private readonly CreateBookingHoldAction $createBookingHold,
        private readonly SchedulingDurationResolver $durations,
    ) {}

    public function handle(
        BookableService $service,
        CarbonInterface $startsAt,
        string $idempotencyKey,
        ?SchedulingLocationSnapshot $location = null,
        ?CarbonInterface $endsAt = null,
    ): BookingHold {
        $serviceId = $this->requiredServiceId($service);
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $resolvedEndsAt = $this->durations->resolveEndsAt(
            service: $service,
            startsAt: $startsAt,
            requestedEndsAt: $endsAt,
            requireExplicitRange: $service->usesRangeDuration(),
        );
        $idempotencyKey = $this->requiredIdempotencyKey($idempotencyKey);

        $existing = BookingHold::query()
            ->with('bookableSlotOffer')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof BookingHold) {
            return $this->matchingExistingHold(
                hold: $existing,
                serviceId: $serviceId,
                startsAt: $startsAt,
                endsAt: $resolvedEndsAt,
                location: $location,
            );
        }

        try {
            return DB::transaction(function () use (
                $serviceId,
                $startsAt,
                $idempotencyKey,
                $location,
                $endsAt,
            ): BookingHold {
                $service = BookableService::query()
                    ->whereKey($serviceId)
                    ->where('status', BookableService::STATUS_ACTIVE)
                    ->where('is_public', true)
                    ->lockForUpdate()
                    ->first();

                if (! $service instanceof BookableService) {
                    throw new DomainException(
                        'The selected service is no longer available for public booking.',
                    );
                }

                $resolvedEndsAt = $this->durations->resolveEndsAt(
                    service: $service,
                    startsAt: $startsAt,
                    requestedEndsAt: $endsAt,
                    requireExplicitRange: $service->usesRangeDuration(),
                );

                $existing = BookingHold::query()
                    ->with('bookableSlotOffer')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing instanceof BookingHold) {
                    return $this->matchingExistingHold(
                        hold: $existing,
                        serviceId: $serviceId,
                        startsAt: $startsAt,
                        endsAt: $resolvedEndsAt,
                        location: $location,
                    );
                }

                $now = CarbonImmutable::now('UTC');
                $slot = $this->currentSlot(
                    service: $service,
                    startsAt: $startsAt,
                    endsAt: $resolvedEndsAt,
                    evaluatedAt: $now,
                    location: $location,
                );

                if (! $slot instanceof BookableSlot) {
                    throw new DomainException(
                        'The selected appointment time is no longer available.',
                    );
                }

                $offer = $this->issueSlotOffer->handle(
                    slot: $slot,
                    issuedAt: $now,
                );

                $hold = $this->createBookingHold->handle(
                    offerId: $offer->offer_id,
                    idempotencyKey: $idempotencyKey,
                    location: $location,
                );

                return $this->matchingExistingHold(
                    hold: $hold->loadMissing('bookableSlotOffer'),
                    serviceId: $serviceId,
                    startsAt: $startsAt,
                    endsAt: $resolvedEndsAt,
                    location: $location,
                );
            });
        } catch (LogicException $exception) {
            $existing = BookingHold::query()
                ->with('bookableSlotOffer')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof BookingHold) {
                return $this->matchingExistingHold(
                    hold: $existing,
                    serviceId: $serviceId,
                    startsAt: $startsAt,
                    endsAt: $resolvedEndsAt,
                    location: $location,
                );
            }

            throw $exception;
        }
    }

    private function currentSlot(
        BookableService $service,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $evaluatedAt,
        ?SchedulingLocationSnapshot $location = null,
    ): ?BookableSlot {
        $candidateDurationMinutes = $this->durations->durationMinutes(
            service: $service,
            startsAt: $startsAt,
            endsAt: $endsAt,
        );

        $slots = $this->findAvailability->handle(new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $endsAt,
            displayTimezone: $service->timezone,
            evaluatedAt: $evaluatedAt,
            location: $location,
            candidateDurationMinutes: $candidateDurationMinutes,
        ));

        foreach ($slots as $slot) {
            if ($slot->bookableServiceId === (int) $service->getKey()
                && $slot->startsAt->equalTo($startsAt)
            ) {
                return $slot;
            }
        }

        return null;
    }

    private function matchingExistingHold(
        BookingHold $hold,
        int $serviceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?SchedulingLocationSnapshot $location,
    ): BookingHold {
        $offer = $hold->bookableSlotOffer;

        if ((int) $hold->bookable_service_id !== $serviceId
            || ! $hold->starts_at?->equalTo($startsAt)
            || ! $hold->ends_at?->equalTo($endsAt)
            || $offer === null
            || $offer->reschedule_appointment_id !== null
            || ($location !== null && ! $hold->locationSnapshot()?->hasSameCommitmentIdentity($location))
        ) {
            throw new DomainException(
                'The reservation replay key was already used for another booking request.',
            );
        }

        return $hold->refresh();
    }

    private function requiredServiceId(BookableService $service): int
    {
        if (! $service->exists || $service->getKey() === null) {
            throw new InvalidArgumentException(
                'Public booking holds require a persisted BookableService.',
            );
        }

        return (int) $service->getKey();
    }

    private function requiredIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException(
                'A non-empty public booking idempotency key is required.',
            );
        }

        if (mb_strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException(
                'The public booking idempotency key cannot exceed 191 characters.',
            );
        }

        return $idempotencyKey;
    }
}