<?php

namespace App\Support\ModuleIntegrations\Scheduling\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Scheduling\Contracts\BookingOfferRewardActionHandler;
use App\Modules\Scheduling\Models\Appointment;
use InvalidArgumentException;

final class ContactTagBookingOfferRewardActionHandler implements BookingOfferRewardActionHandler
{
    public function key(): string
    {
        return 'contact_tag';
    }

    public function apply(
        Contact $contact,
        Appointment $appointment,
        array $payload,
    ): void {
        $tag = is_string($payload['tag'] ?? null)
            ? trim((string) $payload['tag'])
            : '';

        if ($tag === '' || mb_strlen($tag) > 255) {
            throw new InvalidArgumentException(
                'Contact-tag booking offer rewards require a tag of 255 characters or fewer.',
            );
        }

        ContactTag::query()->firstOrCreate([
            'contact_id' => $contact->getKey(),
            'tag' => $tag,
        ]);
    }
}