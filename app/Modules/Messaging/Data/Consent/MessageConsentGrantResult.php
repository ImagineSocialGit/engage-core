<?php

namespace App\Modules\Messaging\Data\Consent;

use App\Modules\Messaging\Models\MessageConsent;

final class MessageConsentGrantResult
{
    public function __construct(
        public readonly MessageConsent $consent,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $requestedScope,
        public readonly string $domain,
        public readonly bool $wasActive,
        public readonly bool $isActive,
        public readonly bool $created,
        public readonly bool $becameActive,
    ) {}
}
