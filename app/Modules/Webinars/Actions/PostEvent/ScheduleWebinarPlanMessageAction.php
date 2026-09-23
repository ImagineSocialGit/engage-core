<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use RuntimeException;

final class ScheduleWebinarPlanMessageAction
{
    public function __construct(
        private readonly ScheduleMessageAction $schedule,
        private readonly MessageGate $gate,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
    ) {}

    /** @param array<string, mixed> $plan */
    public function handle(
        WebinarRegistration $registration,
        array $plan,
        string $ruleId,
        array $rule,
        string $templateKey,
        Carbon $sendAt,
    ): ?ScheduledMessage {
        $channel = (string) $rule['channel'];
        $registration->loadMissing('contact', 'webinar', 'webinar.webinarSeries');
        $contact = $registration->contact;
        $webinar = $registration->webinar;

        if (! $contact || ! $webinar || $registration->status === 'cancelled'
            || filled($registration->cancelled_at)) {
            return null;
        }

        $accepted = data_get($registration->meta, 'accepted_channels.transactional');

        if (is_array($accepted) && ! in_array($channel, $accepted, true)) {
            return null;
        }

        if (! $this->gate->allows($contact, $channel, 'transactional', 'webinar', 'post_event_plan')) {
            return null;
        }

        $template = MessageTemplate::query()->where('key', $templateKey)->first();
        $version = $template?->currentVersion;

        if (! $template || ! $template->isActive() || $template->channel !== $channel || ! $version) {
            throw new RuntimeException("Post-webinar template [{$templateKey}] is unavailable.");
        }

        $values = WebinarMessageData::fromRegistration($registration)->toArray();
        $tokens = array_intersect_key($values, array_flip([
            'first_name', 'name', 'webinar_title', 'webinar_playback_url',
            'webinar_booking_url', 'webinar_start_date', 'webinar_start_time',
        ]));
        $payload = $this->payloadResolver->resolve(
            recipient: $contact,
            channel: $channel,
            purpose: 'transactional',
            scope: 'webinar',
            messageType: 'post_event_plan',
            definitionPayload: $version->payload(),
            payload: ['tokens' => $tokens],
        );

        if ($payload === null) {
            return null;
        }

        $activation = (string) ($plan['activated_at'] ?? '');

        return $this->schedule->handle(
            recipient: $contact,
            channel: $channel,
            purpose: 'transactional',
            scope: 'webinar',
            messageType: 'post_event_plan',
            payloadClass: $channel === 'email' ? EmailPayload::class : SmsPayload::class,
            payload: $payload,
            sendAt: $sendAt,
            context: $registration,
            behaviorOwner: $webinar,
            dedupeKey: 'webinar_post_event_plan:'.$webinar->getKey().':'.$registration->getKey().':'.$ruleId,
            meta: [
                'post_event' => [
                    'type' => 'plan',
                    'activation' => $activation,
                    'template_key' => $templateKey,
                    'rule_key' => $ruleId,
                    'revision' => (int) ($rule['revision'] ?? 1),
                ],
                'webinar_id' => $webinar->getKey(),
            ],
            queue: 'post_event',
            dispatchKeys: ['webinar_post_event_plan'],
            messageTemplateVersionId: $version->getKey(),
        );
    }
}