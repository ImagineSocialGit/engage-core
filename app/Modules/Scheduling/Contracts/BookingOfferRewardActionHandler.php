<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;

interface BookingOfferRewardActionHandler
{
    public function key(): string;

    /** @param array<string, mixed> $payload */
    public function apply(
        Contact $contact,
        Appointment $appointment,
        array $payload,
    ): void;
}