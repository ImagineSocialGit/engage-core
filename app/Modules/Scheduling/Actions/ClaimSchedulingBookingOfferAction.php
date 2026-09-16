<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\BookingEligibilityIdentity;
use App\Modules\Scheduling\Exceptions\BookingOfferEligibilityException;
use App\Modules\Scheduling\Exceptions\SchedulingBookingOfferExhaustedException;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingBookingOffer;
use App\Modules\Scheduling\Models\SchedulingBookingOfferClaim;
use App\Modules\Scheduling\Services\BookingEligibilityProviderRegistry;
use App\Modules\Scheduling\Services\BookingOfferRewardActionHandlerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ClaimSchedulingBookingOfferAction
{
    public function __construct(
        private readonly BookingEligibilityProviderRegistry $eligibilityProviders,
        private readonly BookingOfferRewardActionHandlerRegistry $rewardActions,
    ) {}

    public function handle(
        Appointment $appointment,
        BookableService $service,
        BookingHold $hold,
        AppointmentBookingData $booking,
    ): ?SchedulingBookingOfferClaim {
        return DB::transaction(fn (): ?SchedulingBookingOfferClaim => $this->claim(
            appointment: $appointment,
            service: $service,
            hold: $hold,
            booking: $booking,
        ));
    }

    private function claim(
        Appointment $appointment,
        BookableService $service,
        BookingHold $hold,
        AppointmentBookingData $booking,
    ): ?SchedulingBookingOfferClaim {
        $selection = data_get($hold->meta, 'booking_offer');

        if (! is_array($selection)) {
            return null;
        }

        $offerId = filter_var($selection['id'] ?? null, FILTER_VALIDATE_INT);
        $code = is_string($selection['code'] ?? null)
            ? SchedulingBookingOffer::normalizeCode($selection['code'])
            : '';

        if (! is_int($offerId) || $offerId < 1 || $code === '') {
            throw new LogicException(
                'The booking hold contains an invalid booking-offer selection.',
            );
        }

        $offer = SchedulingBookingOffer::query()
            ->with(['conditions', 'rewards.actions'])
            ->whereKey($offerId)
            ->lockForUpdate()
            ->first();

        if (! $offer instanceof SchedulingBookingOffer
            || (int) $offer->bookable_service_id !== (int) $service->getKey()
            || ! hash_equals($offer->code, $code)
        ) {
            throw new BookingOfferEligibilityException(
                'This booking offer is no longer available for this appointment type.',
            );
        }

        if (! $offer->isActive()) {
            throw new BookingOfferEligibilityException(
                'This booking offer is no longer active.',
            );
        }

        $contact = $booking->contact;
        $email = $booking->attendeeEmail();

        if ($contact === null || ! is_string($email) || trim($email) === '') {
            throw new BookingOfferEligibilityException(
                $this->ineligibleMessage($offer),
            );
        }

        $evaluatedAt = $this->offerAppliedAt($selection);
        $qualification = $this->qualification(
            offer: $offer,
            contactId: (int) $contact->getKey(),
            email: strtolower(trim($email)),
            evaluatedAt: $evaluatedAt,
        );
        $scopeKey = $qualification['scope_key'];

        $existingClaim = SchedulingBookingOfferClaim::query()
            ->where('scheduling_booking_offer_id', $offer->getKey())
            ->where('qualification_scope_key', $scopeKey)
            ->where('contact_id', $contact->getKey())
            ->lockForUpdate()
            ->first();

        if ($existingClaim instanceof SchedulingBookingOfferClaim) {
            throw new BookingOfferEligibilityException(
                'This booking offer has already been claimed for this contact in the current qualification period.',
            );
        }

        $lastClaimNumber = (int) SchedulingBookingOfferClaim::query()
            ->where('scheduling_booking_offer_id', $offer->getKey())
            ->where('qualification_scope_key', $scopeKey)
            ->max('claim_number');
        $claimNumber = $lastClaimNumber + 1;

        if ($offer->claim_limit !== null
            && $claimNumber > (int) $offer->claim_limit
        ) {
            throw new SchedulingBookingOfferExhaustedException(
                $this->exhaustedMessage($offer),
            );
        }

        $claim = SchedulingBookingOfferClaim::query()->create([
            'scheduling_booking_offer_id' => $offer->getKey(),
            'appointment_id' => $appointment->getKey(),
            'contact_id' => $contact->getKey(),
            'qualification_scope_key' => $scopeKey,
            'claim_number' => $claimNumber,
            'qualification_meta' => $qualification['meta'],
            'claimed_at' => CarbonImmutable::now('UTC'),
        ]);

        $rewardNames = [];
        $appliedActions = [];

        foreach ($offer->rewards as $reward) {
            if ($claimNumber > (int) $reward->max_claim_number) {
                continue;
            }

            $rewardNames[] = $reward->name;

            foreach ($reward->actions as $action) {
                try {
                    $this->rewardActions->apply(
                        provider: $action->provider,
                        contact: $contact,
                        appointment: $appointment,
                        payload: is_array($action->payload) ? $action->payload : [],
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new LogicException(
                        "Booking offer reward action [{$action->provider}] could not be applied.",
                        previous: $exception,
                    );
                }

                $appliedActions[] = [
                    'provider' => $action->provider,
                    'payload' => $action->payload,
                ];
            }
        }

        $appointmentMeta = is_array($appointment->meta)
            ? $appointment->meta
            : [];
        data_set($appointmentMeta, 'booking_offer', [
            'offer_id' => $offer->getKey(),
            'offer_code' => $offer->code,
            'offer_name' => $offer->name,
            'claim_id' => $claim->getKey(),
            'claim_number' => $claimNumber,
            'qualification_scope_key' => $scopeKey,
            'rewards' => $rewardNames,
            'actions' => $appliedActions,
        ]);
        $appointment->forceFill(['meta' => $appointmentMeta])->save();

        return $claim->refresh();
    }

    /**
     * @return array{scope_key:string,meta:array<string,mixed>}
     */
    private function qualification(
        SchedulingBookingOffer $offer,
        int $contactId,
        string $email,
        CarbonImmutable $evaluatedAt,
    ): array {
        if ($offer->conditions->isEmpty()) {
            return [
                'scope_key' => 'global',
                'meta' => [
                    'conditions' => [],
                    'evaluated_at' => $evaluatedAt->toISOString(),
                ],
            ];
        }

        $resolutions = [];
        $scopeParts = [];

        foreach ($offer->conditions as $condition) {
            try {
                $identity = $this->eligibilityProviders->resolve(
                    provider: $condition->provider,
                    criteria: is_array($condition->criteria) ? $condition->criteria : [],
                    email: $email,
                    evaluatedAt: $evaluatedAt,
                );
            } catch (InvalidArgumentException) {
                throw new BookingOfferEligibilityException(
                    'This booking qualification source is currently unavailable.',
                );
            }

            if (! $identity instanceof BookingEligibilityIdentity
                || $identity->contactId !== $contactId
            ) {
                throw new BookingOfferEligibilityException(
                    $this->ineligibleMessage($offer),
                );
            }

            $scopeParts[] = $condition->provider.':'.$identity->scopeKey;
            $resolutions[] = [
                'provider' => $condition->provider,
                'criteria' => $condition->criteria,
                'scope_key' => $identity->scopeKey,
                'meta' => $identity->meta,
            ];
        }

        return [
            'scope_key' => $this->combinedScopeKey($scopeParts),
            'meta' => [
                'conditions' => $resolutions,
                'evaluated_at' => $evaluatedAt->toISOString(),
            ],
        ];
    }

    /** @param array<int, string> $parts */
    private function combinedScopeKey(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        try {
            $encoded = json_encode($parts, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new LogicException(
                'Booking offer qualification scope could not be encoded.',
                previous: $exception,
            );
        }

        return 'combined:'.hash('sha256', $encoded);
    }

    /** @param array<string, mixed> $selection */
    private function offerAppliedAt(array $selection): CarbonImmutable
    {
        $value = $selection['applied_at'] ?? null;

        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                // Fall through to current time for older or malformed transient state.
            }
        }

        return CarbonImmutable::now('UTC');
    }

    private function ineligibleMessage(SchedulingBookingOffer $offer): string
    {
        $message = is_string($offer->ineligible_message)
            ? trim($offer->ineligible_message)
            : '';

        return $message !== ''
            ? $message
            : 'That offer code is not available for this contact.';
    }

    private function exhaustedMessage(SchedulingBookingOffer $offer): string
    {
        $message = is_string($offer->exhausted_message)
            ? trim($offer->exhausted_message)
            : '';

        return $message !== ''
            ? $message
            : 'All available claims for this offer have been used for the current qualification period.';
    }
}