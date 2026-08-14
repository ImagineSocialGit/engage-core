<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePublicBookingHoldAction
{
    public function __construct(
        private readonly CreateBookingHoldAction $createBookingHold,
    ) {}

    public function handle(
        string $offerId,
        string $idempotencyKey,
    ): BookingHold {
        return DB::transaction(function () use ($offerId, $idempotencyKey): BookingHold {
            $offer = BookableSlotOffer::query()
                ->where('offer_id', trim($offerId))
                ->lockForUpdate()
                ->first();

            if (! $offer instanceof BookableSlotOffer) {
                throw new DomainException('The selected slot offer could not be found.');
            }

            if ($offer->reschedule_appointment_id !== null) {
                throw new DomainException(
                    'Reschedule slot offers cannot be used for public booking.',
                );
            }

            $existing = BookingHold::query()
                ->where('idempotency_key', trim($idempotencyKey))
                ->lockForUpdate()
                ->first();

            if ($existing instanceof BookingHold) {
                if ((int) $existing->bookable_slot_offer_id !== (int) $offer->getKey()) {
                    throw new DomainException(
                        'That booking replay key was already used for another selection.',
                    );
                }

                return $this->createBookingHold->handle(
                    offerId: $offer->offer_id,
                    idempotencyKey: $idempotencyKey,
                );
            }

            $service = BookableService::withTrashed()
                ->whereKey($offer->bookable_service_id)
                ->lockForUpdate()
                ->first();

            if (! $service instanceof BookableService
                || $service->trashed()
                || $service->status !== BookableService::STATUS_ACTIVE
                || ! $service->is_public
            ) {
                throw new DomainException(
                    'The selected service is no longer available for public booking.',
                );
            }

            return $this->createBookingHold->handle(
                offerId: $offer->offer_id,
                idempotencyKey: $idempotencyKey,
            );
        });
    }
}