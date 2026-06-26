<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageConsentGranted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly Contact $contact,
        public readonly MessageConsent $messageConsent,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly ?Model $context = null,
        public readonly array $data = [],
    ) {}
}