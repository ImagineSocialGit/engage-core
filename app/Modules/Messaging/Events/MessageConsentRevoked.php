<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageConsentRevoked
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly Contact $contact,
        public readonly ConsentRevocation $consentRevocation,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly array $data = [],
    ) {}
}