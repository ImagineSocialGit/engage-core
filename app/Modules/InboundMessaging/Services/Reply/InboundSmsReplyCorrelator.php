<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Services\Sms\CanonicalSmsPhoneMatcher;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class InboundSmsReplyCorrelator
{
    public function __construct(
        private readonly CanonicalSmsPhoneMatcher $phoneMatcher,
    ) {}

    public function correlate(
        Contact $contact,
        ?string $fromValue,
        ?Carbon $receivedAt = null,
    ): ?ScheduledMessage {
        $normalizedFrom = $this->phoneMatcher->normalize($fromValue);

        if ($normalizedFrom === null) {
            return null;
        }

        $receivedAt ??= now();
        $lookbackDays = max(1, (int) config(
            'messaging.inbound.reply_correlation.sms_lookback_days',
            90,
        ));

        return ScheduledMessage::query()
            ->where('recipient_type', $contact->getMorphClass())
            ->where('recipient_id', $contact->getKey())
            ->where('channel', 'sms')
            ->where('status', ScheduledMessage::STATUS_SENT)
            ->where('send_at', '<=', $receivedAt)
            ->where('send_at', '>=', $receivedAt->copy()->subDays($lookbackDays))
            ->whereHas('deliveryAttempts', function (Builder $query) use (
                $normalizedFrom,
            ): void {
                $query->where(
                    'status',
                    ScheduledMessageDeliveryAttempt::STATUS_SENT,
                );

                $this->phoneMatcher->whereEquivalent(
                    $query,
                    'scheduled_message_delivery_attempts.destination',
                    $normalizedFrom,
                );
            })
            ->orderByDesc('send_at')
            ->orderByDesc('id')
            ->first();
    }
}