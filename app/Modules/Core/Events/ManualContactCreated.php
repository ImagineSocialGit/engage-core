<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ManualContactCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly bool $existingRelationshipConfirmed,
        public readonly ?int $actorUserId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}
}