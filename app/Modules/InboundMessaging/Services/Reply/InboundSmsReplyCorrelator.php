<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class InboundSmsReplyCorrelator
{
    public function correlate(
        Contact $contact,
        ?string $fromValue,
        ?Carbon $receivedAt = null,
    ): ?ScheduledMessage {
        if (! is_string($fromValue) || trim($fromValue) === '') {
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
            ->whereHas('deliveryAttempts', function (Builder $query) use ($fromValue): void {
                $query
                    ->where('status', ScheduledMessageDeliveryAttempt::STATUS_SENT)
                    ->where('destination', trim($fromValue));
            })
            ->orderByDesc('send_at')
            ->orderByDesc('id')
            ->first();
    }
}