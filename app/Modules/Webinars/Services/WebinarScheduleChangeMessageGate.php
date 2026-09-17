<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\ProcessWebinarScheduleChangeAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use Illuminate\Database\Eloquent\Model;

class WebinarScheduleChangeMessageGate implements MessageRecipientGate
{
    public function supports(Model $recipient): bool
    {
        return $recipient instanceof Contact;
    }

    public function allows(Model $recipient, string $channel, ?string $type = null, array $context = []): bool
    {
        return $this->denialReason($recipient, $channel, $type, $context) === null;
    }

    public function denialReason(Model $recipient, string $channel, ?string $type = null, array $context = []): ?string
    {
        $message = $context['scheduled_message'] ?? null;

        if (! $message instanceof ScheduledMessage
            || $message->message_type !== 'webinar_schedule_change') {
            return null;
        }

        $registration = $message->context;
        $change = $message->behaviorOwner;

        if (! $registration instanceof WebinarRegistration
            || ! $change instanceof WebinarScheduleChange
            || ! in_array($change->status, [
                WebinarScheduleChange::STATUS_DISPATCHING,
                WebinarScheduleChange::STATUS_COMPLETED,
            ], true)
            || ! $registration->contact?->is($recipient)
            || $registration->cancelled_at !== null
            || $registration->status === 'cancelled'
            || ($registration->registered_at ?? $registration->created_at)?->greaterThan($change->created_at)
            || (int) $registration->webinar_id !== (int) $change->webinar_id
            || ! $change->webinar
            || ! app(ProcessWebinarScheduleChangeAction::class)->isCurrent($change, $change->webinar)) {
            return 'webinar_schedule_change_no_longer_current';
        }

        $accepted = data_get($registration->meta, 'accepted_channels.transactional');

        return is_array($accepted) && ! in_array($channel, $accepted, true)
            ? 'webinar_schedule_change_channel_not_accepted'
            : null;
    }
}