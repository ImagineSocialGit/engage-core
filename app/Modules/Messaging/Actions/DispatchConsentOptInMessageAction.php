<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;

class DispatchConsentOptInMessageAction
{
    public function __construct(
        private readonly BuildConsentOptInMessageIntentAction $buildIntent,
        private readonly DispatchMessageIntentsAction $dispatchIntents,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resolverContext
     * @return array<int, ScheduledMessage>
     */
    public function handle(
        Contact $contact,
        MessageConsentGrantResult $grant,
        array $payload = [],
        ?Model $context = null,
        array $resolverContext = [],
    ): array {
        $intent = $this->buildIntent->handle(
            contact: $contact,
            grant: $grant,
            payload: $payload,
            context: $context,
            resolverContext: $resolverContext,
        );

        return $intent
            ? $this->dispatchIntents->handle([$intent])
            : [];
    }
}
