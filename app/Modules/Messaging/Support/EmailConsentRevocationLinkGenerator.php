<?php

namespace App\Modules\Messaging\Support;

use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\URL;

class EmailConsentRevocationLinkGenerator
{
    public function marketingUnsubscribeUrl(Contact $contact): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->addDays(
                config('messaging.email.unsubscribe.signed_url_expiration_days', 30)
            ),
            parameters: [
                'contact' => $contact,
            ],
        );
    }

    public function transactionalOptOutUrl(Contact $contact, string $scope): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.transactional-opt-out',
            expiration: now()->addDays(
                config('messaging.email.transactional_opt_out.signed_url_expiration_days', 30)
            ),
            parameters: [
                'contact' => $contact,
                'scope' => $scope,
            ],
        );
    }
}