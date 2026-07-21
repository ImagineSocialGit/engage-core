<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class IssueBookableSlotOfferAction
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
    ) {}

    public function handle(
        BookableSlot $slot,
        ?CarbonInterface $issuedAt = null,
    ): BookableSlotOffer {
        $issuedAt = $issuedAt !== null
            ? CarbonImmutable::instance($issuedAt)->utc()
            : CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($slot, $issuedAt): BookableSlotOffer {
            $service = BookableService::withTrashed()
                ->whereKey($slot->bookableServiceId)
                ->lockForUpdate()
                ->first();

            if (! $service instanceof BookableService
                || $service->trashed()
                || $service->status !== BookableService::STATUS_ACTIVE
            ) {
                throw new DomainException(
                    'The selected service is no longer available for booking.',
                );
            }

            $host = $this->lockedHost($slot);
            $currentSlot = $this->exactCurrentSlot(
                service: $service,
                host: $host,
                slot: $slot,
                evaluatedAt: $issuedAt,
            );

            if (! $currentSlot instanceof BookableSlot) {
                throw new DomainException(
                    'The selected slot is no longer available.',
                );
            }

            $ttlSeconds = max(
                1,
                (int) config('scheduling.slot_offers.ttl_seconds', 300),
            );

            return BookableSlotOffer::query()->create([
                'bookable_service_id' => $currentSlot->bookableServiceId,
                'scheduling_host_id' => $currentSlot->schedulingHostId,
                'starts_at' => $currentSlot->startsAt,
                'ends_at' => $currentSlot->endsAt,
                'display_timezone' => $currentSlot->displayTimezone,
                'capacity' => $currentSlot->capacity,
                'remaining_capacity' => $currentSlot->remainingCapacity,
                'source_scopes' => $currentSlot->sourceScopes,
                'source_window_ids' => $currentSlot->sourceWindowIds,
                'issued_at' => $issuedAt,
                'expires_at' => $issuedAt->addSeconds($ttlSeconds),
            ]);
        });
    }

    private function lockedHost(BookableSlot $slot): ?SchedulingHost
    {
        if ($slot->schedulingHostId === null) {
            return null;
        }

        $host = SchedulingHost::withTrashed()
            ->whereKey($slot->schedulingHostId)
            ->lockForUpdate()
            ->first();

        if (! $host instanceof SchedulingHost
            || $host->trashed()
            || $host->status !== SchedulingHost::STATUS_ACTIVE
        ) {
            throw new DomainException(
                'The selected scheduling host is no longer available.',
            );
        }

        return $host;
    }

    private function exactCurrentSlot(
        BookableService $service,
        ?SchedulingHost $host,
        BookableSlot $slot,
        CarbonImmutable $evaluatedAt,
    ): ?BookableSlot {
        $search = new AvailabilitySearch(
            service: $service,
            startsAt: $slot->startsAt,
            endsAt: $slot->endsAt,
            host: $host,
            displayTimezone: $slot->displayTimezone,
            evaluatedAt: $evaluatedAt,
        );

        foreach ($this->findAvailability->handle($search) as $candidate) {
            if ($candidate->bookableServiceId === $slot->bookableServiceId
                && $candidate->schedulingHostId === $slot->schedulingHostId
                && $candidate->startsAt->equalTo($slot->startsAt)
                && $candidate->endsAt->equalTo($slot->endsAt)
            ) {
                return $candidate;
            }
        }

        return null;
    }
}