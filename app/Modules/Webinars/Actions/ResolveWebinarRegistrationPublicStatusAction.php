<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;

class ResolveWebinarRegistrationPublicStatusAction
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DELAYED = 'delayed';
    public const STATUS_CANCELLED = 'cancelled';

    public function handle(WebinarRegistration $registration): string
    {
        if ($registration->status === 'cancelled' || $registration->cancelled_at !== null) {
            return self::STATUS_CANCELLED;
        }

        $meta = is_array($registration->meta) ? $registration->meta : [];
        $state = is_array(
            $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
        )
            ? $meta[WebinarRegistrationFinalizationResult::META_KEY]
            : [];
        $mode = (string) ($state['mode'] ?? '');
        $status = (string) ($state['status'] ?? '');

        // Later consent-only acknowledgement work must not make an already
        // completed provider registration look unfinished to the attendee.
        if (
            $mode === 'consent_acknowledgements'
            && filled($state['initial_completed_at'] ?? null)
        ) {
            return self::STATUS_CONFIRMED;
        }

        if ($status === 'completed') {
            return self::STATUS_CONFIRMED;
        }

        if (in_array($status, ['failed', 'reconciliation_required'], true)) {
            return self::STATUS_DELAYED;
        }

        // Compatibility for registrations created before durable finalization
        // state existed. Never infer confirmation from a merely pending row.
        if ($state === [] && in_array($registration->status, ['registered', 'attended'], true)) {
            return self::STATUS_CONFIRMED;
        }

        return self::STATUS_PROCESSING;
    }
}