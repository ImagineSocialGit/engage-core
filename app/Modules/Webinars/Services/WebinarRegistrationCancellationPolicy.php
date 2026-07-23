<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\WebinarRegistration;
use LogicException;

class WebinarRegistrationCancellationPolicy
{
    public const STATE_CANCELLABLE = 'cancellable';
    public const STATE_ALREADY_CANCELLED = 'already_cancelled';
    public const STATE_INELIGIBLE = 'ineligible';

    /**
     * @var array<int, string>
     */
    private const CANCELLABLE_STATUSES = [
        'pending',
        'registered',
    ];

    public function stateFor(WebinarRegistration $registration): string
    {
        $status = strtolower(trim((string) $registration->status));
        $hasCancellationTimestamp = $registration->cancelled_at !== null;

        if ($status === 'cancelled' && $hasCancellationTimestamp) {
            return self::STATE_ALREADY_CANCELLED;
        }

        if (
            ! $hasCancellationTimestamp
            && in_array($status, self::CANCELLABLE_STATUSES, true)
        ) {
            return self::STATE_CANCELLABLE;
        }

        return self::STATE_INELIGIBLE;
    }

    public function assertCancellableOrAlreadyCancelled(
        WebinarRegistration $registration,
    ): string {
        $state = $this->stateFor($registration);

        if ($state === self::STATE_INELIGIBLE) {
            throw new LogicException(
                'Webinar registration cancellation is not permitted for its current state.',
            );
        }

        return $state;
    }
}