<?php

namespace App\Integrations\Messaging\Sms\Telnyx;

use App\Modules\Messaging\Models\MessageSuppression;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TelnyxMessageEventWebhookHandler
{
    public function __construct(
        private readonly TelnyxWebhookVerifier $verifier,
        private readonly WebhookInbox $webhookInbox,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $this->verifier->isValid($request)) {
            return response(status: 403);
        }

        $eventType = $this->nullableString($request->input('data.event_type'));

        if ($eventType === null) {
            return response(status: 422);
        }

        if (in_array(
            $eventType,
            config('sms.providers.telnyx.webhooks.inbound_event_types', []),
            true,
        )) {
            return response(status: 422);
        }

        $providerEventId = $this->nullableString($request->input('data.id'));

        if ($providerEventId === null) {
            return response(status: 422);
        }

        $signature = $this->nullableString(
            $request->header('Telnyx-Signature-Ed25519'),
        ) ?? '';

        $this->webhookInbox->process(
            provider: MessageSuppression::PROVIDER_TELNYX,
            payload: $request->all(),
            processor: static fn (): array => ['http_status' => 204],
            providerEventId: $providerEventId,
            signatureFingerprint: hash('sha256', $signature),
            eventType: $eventType,
        );

        return response()->noContent();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}