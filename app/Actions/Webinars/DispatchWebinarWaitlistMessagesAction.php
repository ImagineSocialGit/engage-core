<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Messaging\Payloads\Webinars\WebinarWaitlistScheduledEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarWaitlistScheduledSmsPayload;
use App\Models\Webinar;
use App\Models\WebinarWaitlistSignup;

class DispatchWebinarWaitlistMessagesAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    public function handle(Webinar $webinar): void
    {
        $webinar->loadMissing('webinarSeries');

        $signups = WebinarWaitlistSignup::query()
            ->with('lead')
            ->where('webinar_series_id', $webinar->webinar_series_id)
            ->whereNull('notified_at')
            ->get();

        foreach ($signups as $signup) {
            if (! $signup->lead) {
                continue;
            }

            $payload = $this->payload($signup, $webinar);

            $this->dispatchMessage(
                signup: $signup,
                webinar: $webinar,
                channel: MessageChannel::Email->value,
                messageType: 'webinar_waitlist_scheduled',
                payloadClass: WebinarWaitlistScheduledEmailPayload::class,
                payload: $payload,
            );

            $this->dispatchMessage(
                signup: $signup,
                webinar: $webinar,
                channel: MessageChannel::Sms->value,
                messageType: 'webinar_waitlist_scheduled',
                payloadClass: WebinarWaitlistScheduledSmsPayload::class,
                payload: $payload,
            );

            $signup->forceFill([
                'notified_at' => now(),
            ])->save();
        }
    }

    private function payload(WebinarWaitlistSignup $signup, Webinar $webinar): array
    {
        return [
            'signup_id' => $signup->id,
            'webinar_id' => $webinar->id,
            'email' => $signup->lead->email,
            'phone' => $signup->lead->phone,
            'webinar_title' => $webinar->webinarSeries?->title ?? 'Upcoming Webinar',
            'registration_url' => $webinar->registration_url,
            'source_ip' => $signup->ip_address,
        ];
    }

    private function dispatchMessage(
        WebinarWaitlistSignup $signup,
        Webinar $webinar,
        string $channel,
        string $messageType,
        string $payloadClass,
        array $payload,
    ): void {
        $this->dispatchMessageAction->handle(
            recipient: $signup->lead,
            channel: $channel,
            messageType: $messageType,
            purpose: MessagePurpose::Transactional->value,
            payloadClass: $payloadClass,
            payload: $payload,
            sendAt: now(),
            context: $signup,
            dedupeKey: $this->dedupeKey($signup, $channel, $messageType),
            meta: [
                'queue' => config('webinars.queues.notifications'),
                'webinar_id' => $webinar->id,
            ],
        );
    }

    private function dedupeKey(WebinarWaitlistSignup $signup, string $channel, string $messageType): string
    {
        return implode(':', [
            'scheduled-message',
            $signup->lead->getMorphClass(),
            $signup->lead->getKey(),
            $signup->getMorphClass(),
            $signup->getKey(),
            $channel,
            $messageType,
        ]);
    }
}