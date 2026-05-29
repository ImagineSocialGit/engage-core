<?php

namespace App\Actions\Sms;

use App\Actions\Messaging\RevokeMessageConsentAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Lead;
use Illuminate\Http\Request;

class HandleTwilioInboundSmsWebhookAction
{
    private const STOP_KEYWORDS = [
        'STOP',
        'STOPALL',
        'UNSUBSCRIBE',
        'CANCEL',
        'END',
        'QUIT',
    ];

    private const HELP_KEYWORDS = [
        'HELP',
        'INFO',
    ];

    private const START_KEYWORDS = [
        'START',
        'YES',
        'UNSTOP',
    ];

    public function __construct(
        private readonly RevokeMessageConsentAction $revokeMessageConsentAction,
    ) {}

    public function handle(Request $request): ?string
    {
        $body = $this->normalizeBody($request->input('Body'));
        $from = $this->normalizePhone($request->input('From'));

        if ($body === null || $from === null) {
            return null;
        }

        if (in_array($body, self::STOP_KEYWORDS, true)) {
            $this->revokeSmsConsent($request, $from);

            return config('sms.webhooks.twilio.stop_response');
        }

        if (in_array($body, self::HELP_KEYWORDS, true)) {
            return config('sms.webhooks.twilio.help_response');
        }

        if (in_array($body, self::START_KEYWORDS, true)) {
            return null;
        }

        return null;
    }

    private function revokeSmsConsent(Request $request, string $from): void
    {
        $lead = Lead::query()
            ->where('phone', $from)
            ->first();

        if (! $lead) {
            return;
        }

        foreach ([MessagePurpose::Transactional, MessagePurpose::Marketing] as $purpose) {
            $this->revokeMessageConsentAction->handle($lead, [
                'channel' => MessageChannel::Sms->value,
                'purpose' => $purpose->value,
                'reason' => ConsentRevocation::REASON_STOP,
                'source' => 'twilio_inbound_sms',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => [
                    'provider' => 'twilio',
                    'message_sid' => $request->input('MessageSid'),
                    'from' => $request->input('From'),
                    'to' => $request->input('To'),
                    'body' => $request->input('Body'),
                    'keyword' => $this->normalizeBody($request->input('Body')),
                ],
            ]);
        }
    }

    private function normalizeBody(mixed $body): ?string
    {
        if (! is_string($body)) {
            return null;
        }

        $body = strtoupper(trim($body));

        return $body === '' ? null : $body;
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $phone = trim($phone);

        return $phone === '' ? null : $phone;
    }
}