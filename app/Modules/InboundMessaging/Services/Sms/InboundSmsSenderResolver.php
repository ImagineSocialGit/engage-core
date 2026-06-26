<?php

namespace App\Modules\InboundMessaging\Services\Sms;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use InvalidArgumentException;

class InboundSmsSenderResolver
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    public function resolve(?string $from): ?Contact
    {
        $from = $this->normalizePhone($from);

        if ($from === null) {
            return null;
        }

        return Contact::query()
            ->where('phone', $from)
            ->first();
    }

    public function normalizePhone(?string $phone): ?string
    {
        try {
            return $this->phoneNumberNormalizer->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}