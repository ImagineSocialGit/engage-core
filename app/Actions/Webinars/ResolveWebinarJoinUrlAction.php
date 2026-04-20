<?php

namespace App\Actions\Webinars;

use App\Models\WebinarRegistration;

class ResolveWebinarJoinUrlAction
{
    public function execute(WebinarRegistration $registration): ?string
    {
        $registration->loadMissing('webinar');

        $webinar = $registration->webinar;

        if (! $webinar) {
            return null;
        }

        $providerJoinUrl = match ($webinar->platform) {
            'zoom' => data_get($registration->meta, 'zoom.join_url'),
            default => null,
        };

        return $providerJoinUrl ?: $webinar->join_url;
    }
}