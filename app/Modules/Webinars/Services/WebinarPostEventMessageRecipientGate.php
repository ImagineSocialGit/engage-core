<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageRecipientGate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Database\Eloquent\Model;

class WebinarPostEventMessageRecipientGate implements MessageRecipientGate
{
    public function __construct(
        private readonly WebinarPostEventReplayPreflight $replayPreflight,
    ) {}

    public function supports(Model $recipient): bool
    {
        return $recipient instanceof Contact;
    }

    public function allows(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): bool {
        return $this->denialReason(
            recipient: $recipient,
            channel: $channel,
            type: $type,
            context: $context,
        ) === null;
    }

    public function denialReason(
        Model $recipient,
        string $channel,
        ?string $type = null,
        array $context = [],
    ): ?string {
        $scheduledMessage = $context['scheduled_message'] ?? null;

        if (! $scheduledMessage instanceof ScheduledMessage) {
            return null;
        }

        $registration = $scheduledMessage->context;

        if (! $registration instanceof WebinarRegistration) {
            return null;
        }

        if (! $this->requiresReplay($scheduledMessage)) {
            return null;
        }

        $webinar = $registration->webinar;

        if (! $webinar) {
            return 'webinar_recording_unavailable';
        }

        return $this->replayPreflight->denialReason($webinar);
    }

    private function requiresReplay(ScheduledMessage $scheduledMessage): bool
    {
        $version = $scheduledMessage->messageTemplateVersion;

        if (! $version) {
            return in_array(
                $scheduledMessage->message_type,
                ['post_attended', 'post_missed'],
                true,
            ) && $scheduledMessage->scope === 'webinar';
        }

        $encoded = json_encode(
            $version->payload(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return is_string($encoded)
            && str_contains($encoded, '{webinar_playback_url}');
    }
}