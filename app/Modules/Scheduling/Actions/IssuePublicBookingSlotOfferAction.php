<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Services\SchedulingDurationResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IssuePublicBookingSlotOfferAction
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly IssueBookableSlotOfferAction $issueSlotOffer,
        private readonly SchedulingDurationResolver $durations,
    ) {}

    public function handle(
        BookableService $service,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt = null,
        ?SchedulingLocationSnapshot $location = null,
    ): BookableSlotOffer {
        if (! $service->exists || $service->getKey() === null) {
            throw new InvalidArgumentException(
                'Public slot offers require a persisted BookableService.',
            );
        }

        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $requestedEndsAt = $endsAt !== null
            ? CarbonImmutable::instance($endsAt)->utc()
            : null;

        return DB::transaction(function () use (
            $service,
            $startsAt,
            $requestedEndsAt,
            $location,
        ): BookableSlotOffer {
            $lockedService = BookableService::withTrashed()
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedService instanceof BookableService
                || $lockedService->trashed()
                || $lockedService->status !== BookableService::STATUS_ACTIVE
                || ! $lockedService->is_public
            ) {
                throw new DomainException(
                    'The selected service is no longer available for public booking.',
                );
            }

            if ($lockedService->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE
                && (! $location instanceof SchedulingLocationSnapshot
                    || ! $location->canonical
                    || ! $location->isCustomerSite())
            ) {
                throw new DomainException(
                    'Provide the normalized customer-site location before selecting a public appointment time.',
                );
            }

            $endsAt = $this->durations->resolveEndsAt(
                service: $lockedService,
                startsAt: $startsAt,
                requestedEndsAt: $requestedEndsAt,
                requireExplicitRange: $lockedService->usesRangeDuration(),
            );
            $now = CarbonImmutable::now('UTC');
            $slot = $this->exactCurrentSlot(
                service: $lockedService,
                startsAt: $startsAt,
                endsAt: $endsAt,
                evaluatedAt: $now,
                location: $location,
            );

            if (! $slot instanceof BookableSlot) {
                throw new DomainException(
                    $lockedService->usesRangeDuration()
                        ? 'That check-in/check-out interval is no longer available.'
                        : 'That appointment time is no longer available.',
                );
            }

            return $this->issueSlotOffer->handle(
                slot: $slot,
                issuedAt: $now,
                location: $location,
            );
        });
    }

    private function exactCurrentSlot(
        BookableService $service,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $evaluatedAt,
        ?SchedulingLocationSnapshot $location,
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
                && $slot->endsAt->equalTo($endsAt)
            ) {
                return $slot;
            }
        }

        return null;
    }
}