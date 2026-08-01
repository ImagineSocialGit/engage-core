<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Contracts\MessageChainExecutionContextProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use RuntimeException;

class WebinarMessageChainExecutionContextProvider implements MessageChainExecutionContextProvider
{
    public function supports(MessageChainEnrollment $enrollment): bool
    {
        $enrollment->loadMissing(['context', 'origin']);

        return $enrollment->origin instanceof Webinar
            && ($enrollment->context instanceof WebinarRegistration
                || $enrollment->context instanceof WebinarWaitlistSignup);
    }

    /**
     * @return array<string, mixed>
     */
    public function values(MessageChainEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['context', 'origin']);
        $webinar = $enrollment->origin;
        $context = $enrollment->context;

        if (! $webinar instanceof Webinar) {
            throw new RuntimeException(
                "MessageChainEnrollment [{$enrollment->getKey()}] has no Webinar origin.",
            );
        }

        if (! $context instanceof WebinarRegistration
            && ! $context instanceof WebinarWaitlistSignup
        ) {
            throw new RuntimeException(
                "MessageChainEnrollment [{$enrollment->getKey()}] has unsupported Webinar context.",
            );
        }

        return $this->valuesFor(
            webinar: $webinar,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function valuesFor(
        Webinar $webinar,
        WebinarRegistration|WebinarWaitlistSignup $context,
    ): array {
        $values = $context instanceof WebinarRegistration
            ? $this->registrationValues($context, $webinar)
            : WebinarMessageData::fromWaitlistSignup(
                signup: $context,
                webinar: $webinar,
            )->toArray();

        return array_replace_recursive($values, [
            'webinar_post_event' => [
                'allowed_channels' => $this->postEventAllowedChannels(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationValues(
        WebinarRegistration $registration,
        Webinar $webinar,
    ): array {
        if ((int) $registration->webinar_id !== (int) $webinar->getKey()) {
            throw new RuntimeException(
                "WebinarRegistration [{$registration->getKey()}] does not belong to Webinar [{$webinar->getKey()}].",
            );
        }

        return WebinarMessageData::fromRegistration($registration)->toArray();
    }

    /**
     * @return array<string, bool>
     */
    private function postEventAllowedChannels(): array
    {
        $configured = config('webinars.post_event.outcome_messages.channels', [
            'email',
        ]);
        $channels = is_array($configured) ? $configured : ['email'];
        $resolved = [];

        foreach ($channels as $channel) {
            if (! is_string($channel) || trim($channel) === '') {
                continue;
            }

            $channel = str_replace('-', '_', strtolower(trim($channel)));

            if (in_array($channel, ['email', 'sms'], true)) {
                $resolved[$channel] = true;
            }
        }

        return $resolved !== []
            ? $resolved
            : ['email' => true];
    }
}