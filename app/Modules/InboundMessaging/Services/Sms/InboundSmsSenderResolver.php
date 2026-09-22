<?php

namespace App\Modules\InboundMessaging\Services\Sms;

use App\Modules\Core\Models\Contact;

class InboundSmsSenderResolver
{
    public function __construct(
        private readonly CanonicalSmsPhoneMatcher $phoneMatcher,
    ) {}

    public function resolve(?string $from): ?Contact
    {
        $normalized = $this->normalizePhone($from);

        if ($normalized === null) {
            return null;
        }

        $matches = $this->phoneMatcher
            ->whereEquivalent(
                Contact::query()->whereNotNull('phone'),
                'contacts.phone',
                $normalized,
            )
            ->orderBy('contacts.id')
            ->limit(2)
            ->get();

        return $matches->count() === 1
            ? $matches->first()
            : null;
    }

    public function normalizePhone(?string $phone): ?string
    {
        return $this->phoneMatcher->normalize($phone);
    }
}