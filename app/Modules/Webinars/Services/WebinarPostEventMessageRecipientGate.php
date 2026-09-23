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
        private readonly WebinarPostEventPlanService $postEventPlans,
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

        $webinar = $registration->webinar;

        if (! $webinar) {
            return 'webinar_recording_unavailable';
        }

        $planMeta = data_get($scheduledMessage->meta, 'post_event');

        if (is_array($planMeta) && ($planMeta['type'] ?? null) === 'plan') {
            $plan = $this->postEventPlans->activeFor($webinar);

            if ($plan === null || ($plan['activated_at'] ?? null) !== ($planMeta['activation'] ?? null)) {
                return 'webinar_post_event_plan_disabled';
            }

            $ruleId = $planMeta['rule_key'] ?? null;

            if (is_string($ruleId) && $ruleId !== '') {
                $rule = $this->postEventPlans->rule($plan, $ruleId);
                if ($rule === null || ($rule['enabled'] ?? false) !== true
                    || (int) ($rule['revision'] ?? 1) !== (int) ($planMeta['revision'] ?? 0)
                    || ($rule['channel'] ?? null) !== $channel
                    || ! in_array($planMeta['template_key'] ?? null, array_filter([
                        $rule['template_key'] ?? null,
                        $rule['alternate_template_key'] ?? null,
                    ]), true)) {
                    return 'webinar_post_event_action_disabled';
                }

                $registration->refresh();
                if ($registration->status === 'cancelled' || filled($registration->cancelled_at)) {
                    return 'webinar_registration_cancelled';
                }

                $outcome = filled($registration->attended_at) ? 'attended' : 'missed';
                if (($rule['outcome'] ?? 'any') !== 'any' && $rule['outcome'] !== $outcome) {
                    return 'webinar_post_event_outcome_changed';
                }
            } else {
                $legacyKeys = $channel === 'sms'
                    ? array_values((array) data_get($plan, 'templates.sms', []))
                    : array_merge(
                        array_values((array) data_get($plan, 'templates.email.recording', [])),
                        array_values((array) data_get($plan, 'templates.email.fallback', [])),
                    );
                if (($plan[$channel.'_enabled'] ?? false) !== true
                    || ! in_array($planMeta['template_key'] ?? null, $legacyKeys, true)) {
                    return 'webinar_post_event_action_disabled';
                }
            }

            if ($this->requiresReplay($scheduledMessage)) {
                $review = data_get($webinar->meta, 'normalized.post_event.review', []);

                if (data_get($review, 'status') === 'suppressed'
                    || data_get($review, 'playback_mode') === 'none'
                    || blank($webinar->playback_url)) {
                    return 'webinar_recording_unavailable';
                }
            }

            return null;
        }

        if (! $this->requiresReplay($scheduledMessage)) {
            return null;
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