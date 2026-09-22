<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Support\Contacts\ContactPhoneNormalizer;

class PhoneNumberNormalizer
{
    public function __construct(
        private readonly ContactPhoneNormalizer $contactPhoneNormalizer,
    ) {}

    public function normalize(
        ?string $phone,
        string $defaultCountryCode = '1',
    ): ?string {
        return $this->contactPhoneNormalizer->normalize(
            phone: $phone,
            defaultCountryCode: $defaultCountryCode,
        );
    }
}