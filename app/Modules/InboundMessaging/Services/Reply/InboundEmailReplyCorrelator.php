<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Support\EmailReplyAddressGenerator;

class InboundEmailReplyCorrelator
{
    public function __construct(
        private readonly EmailReplyAddressGenerator $replyAddressGenerator,
    ) {}

    /** @param array<int, string> $toAddresses */
    public function correlate(Contact $contact, array $toAddresses): ?ScheduledMessage
    {
        foreach ($toAddresses as $address) {
            if (! is_string($address)) {
                continue;
            }

            $scheduledMessage = $this->replyAddressGenerator->resolve($address);

            if (! $scheduledMessage instanceof ScheduledMessage) {
                continue;
            }

            if ($scheduledMessage->recipient_type === $contact->getMorphClass()
                && (int) $scheduledMessage->recipient_id === (int) $contact->getKey()
            ) {
                return $scheduledMessage;
            }
        }

        return null;
    }
}