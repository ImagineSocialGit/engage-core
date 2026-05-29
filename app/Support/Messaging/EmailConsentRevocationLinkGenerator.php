<?php

namespace App\Support\Messaging;

use App\Models\Lead;
use Illuminate\Support\Facades\URL;

class EmailConsentRevocationLinkGenerator
{
    public function marketingUnsubscribeUrl(Lead $lead): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->addDays(
                config('messaging.email.unsubscribe.signed_url_expiration_days', 30)
            ),
            parameters: [
                'lead' => $lead,
            ],
        );
    }

    public function transactionalOptOutUrl(Lead $lead): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.transactional-opt-out',
            expiration: now()->addDays(
                config('messaging.email.transactional_opt_out.signed_url_expiration_days', 30)
            ),
            parameters: [
                'lead' => $lead,
            ],
        );
    }
}