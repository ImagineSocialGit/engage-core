<?php

namespace App\Integrations\Messaging\Sms\Telnyx;

use App\Modules\InboundMessaging\Contracts\Sms\SmsWebhookHandler;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TelnyxWebhookHandler implements SmsWebhookHandler
{
    private readonly TelnyxWebhookVerifier $verifier;

    public function __construct(?TelnyxWebhookVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? app(TelnyxWebhookVerifier::class);
    }

    public function provider(): string
    {
        return 'telnyx';
    }

    public function isValid(Request $request): bool
    {
        return $this->verifier->isValid($request);
    }

    public function payloadFrom(Request $request): SmsWebhookPayload
    {
        $eventType = $this->stringOrNull($request->input('data.event_type'));
        $payload = $request->input('data.payload', []);

        return SmsWebhookPayload::fromRequest(
            provider: $this->provider(),
            request: $request,
            eventType: $eventType,
            isInboundMessage: $this->isInboundEventType($eventType),
            providerEventId: $this->stringOrNull($request->input('data.id')),
            providerMessageId: $this->stringOrNull(data_get($payload, 'id')),
            providerContextId: $this->stringOrNull(data_get($payload, 'messaging_profile_id')),
            from: $this->stringOrNull(data_get($payload, 'from.phone_number')),
            to: $this->stringOrNull(data_get($payload, 'to.0.phone_number')),
            body: $this->stringOrNull(data_get($payload, 'text')),
            receivedAt: $this->carbonOrNull(data_get($payload, 'received_at')),
        );
    }

    public function response(?string $message = null): Response
    {
        return response()->noContent();
    }

    private function isInboundEventType(?string $eventType): bool
    {
        if ($eventType === null) {
            return false;
        }

        return in_array(
            $eventType,
            config('sms.providers.telnyx.webhooks.inbound_event_types', []),
            true,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function carbonOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}