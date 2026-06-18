<?php

namespace App\Services\Messaging\Sms;

use App\Models\Contact;
use App\Services\Messaging\PhoneNumberNormalizer;
use InvalidArgumentException;

class InboundSmsRecipientResolver
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