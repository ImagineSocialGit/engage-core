<?php

namespace App\Modules\InboundMessaging\Services\Sms;

use App\Modules\InboundMessaging\Models\InboundMessage;

class InboundSmsMessageClassifier
{
    public function classify(string $provider, ?string $body): string
    {
        if ($body === null) {
            return InboundMessage::CLASSIFICATION_NORMAL_REPLY;
        }

        if ($this->isStopKeyword($body)) {
            return InboundMessage::CLASSIFICATION_CONSENT_REVOCATION;
        }

        if ($this->isHelpKeyword($body)) {
            return InboundMessage::CLASSIFICATION_HELP;
        }

        return InboundMessage::CLASSIFICATION_NORMAL_REPLY;
    }

    private function isStopKeyword(string $body): bool
    {
        return in_array(
            strtolower($body),
            config('messaging.sms.inbound.stop_keywords', []),
            true,
        );
    }

    private function isHelpKeyword(string $body): bool
    {
        return in_array(
            strtolower($body),
            config('messaging.sms.inbound.help_keywords', []),
            true,
        );
    }
}