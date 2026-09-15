<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;

final class CancelContactMessagingRuntimeAction
{
    public const REASON = 'contact_deleted';

    public function __construct(
        private readonly CancelMessageChainEnrollmentAction $cancelEnrollment,
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    public function handle(Contact $contact): void
    {
        MessageChainEnrollment::query()
            ->where('recipient_type', $contact->getMorphClass())
            ->where('recipient_id', $contact->getKey())
            ->whereIn('status', [
                MessageChainEnrollment::STATUS_ACTIVE,
                MessageChainEnrollment::STATUS_PAUSED,
            ])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->each(function (int $enrollmentId): void {
                $this->cancelEnrollment->handle(
                    enrollment: $enrollmentId,
                    reason: self::REASON,
                    skipPendingMessages: true,
                );
            });

        $this->skipScheduledMessages->forRecipient(
            recipient: $contact,
            reason: self::REASON,
        );
    }
}