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

        if ($this->isConfiguredKeyword($body, 'stop_keywords')) {
            return InboundMessage::CLASSIFICATION_CONSENT_REVOCATION;
        }

        if ($this->isConfiguredKeyword($body, 'start_keywords')) {
            return InboundMessage::CLASSIFICATION_CONSENT_GRANT;
        }

        if ($this->isConfiguredKeyword($body, 'help_keywords')) {
            return InboundMessage::CLASSIFICATION_HELP;
        }

        return InboundMessage::CLASSIFICATION_NORMAL_REPLY;
    }

    private function isConfiguredKeyword(string $body, string $configKey): bool
    {
        $keywords = config("messaging.sms.inbound.{$configKey}", []);

        if (! is_array($keywords)) {
            return false;
        }

        return in_array(
            strtolower(trim($body)),
            array_map(
                static fn (mixed $keyword): string => is_string($keyword)
                    ? strtolower(trim($keyword))
                    : '',
                $keywords,
            ),
            true,
        );
    }
}