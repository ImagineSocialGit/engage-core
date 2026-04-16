<?php

namespace App\Actions\Webinars;

use App\Models\WebinarRegistration;
use Carbon\CarbonInterface;

class MarkWebinarRegistrationConvertedAction
{
    public function execute(
        int $leadId,
        ?CarbonInterface $convertedAt = null
    ): ?WebinarRegistration {
        $convertedAt ??= now();

        $registration = WebinarRegistration::query()
            ->where('lead_id', $leadId)
            ->whereNull('converted_at')
            ->orderByDesc('registered_at')
            ->first();

        if (! $registration) {
            return null;
        }

        $registration->update([
            'converted_at' => $convertedAt,
        ]);

        return $registration;
    }
}