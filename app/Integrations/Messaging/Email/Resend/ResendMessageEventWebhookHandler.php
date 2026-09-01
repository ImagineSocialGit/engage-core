<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\Messaging\Actions\HandleEmailProviderEventAction;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

final class ResendMessageEventWebhookHandler
{
    public function __construct(
        private readonly ResendWebhookVerifier $verifier,
        private readonly HandleEmailProviderEventAction $providerEvents,
        private readonly WebhookInbox $webhookInbox,
    ) {}

    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        $eventId = $this->header($request, 'svix-id');
        $signature = $this->header($request, 'svix-signature');

        if (! $this->verifier->isValid(
            payload: $rawBody,
            headers: [
                'svix-id' => $eventId,
                'svix-timestamp' => $request->header('svix-timestamp'),
                'svix-signature' => $signature,
            ],
        )) {
            return response(status: 403);
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response(status: 400);
        }

        if (! is_array($payload)) {
            return response(status: 400);
        }

        $eventType = $this->nullableString($payload['type'] ?? null);

        if ($eventType === null) {
            return response(status: 422);
        }

        if ($eventType === 'email.received') {
            return response(status: 422);
        }

        $this->webhookInbox->process(
            provider: MessageSuppression::PROVIDER_RESEND,
            payload: $payload,
            processor: function () use ($payload, $eventId): array {
                $this->providerEvents->handle(
                    event: $payload,
                    sourceEventId: $eventId,
                    provider: MessageSuppression::PROVIDER_RESEND,
                );

                return ['http_status' => 204];
            },
            providerEventId: $eventId,
            signatureFingerprint: hash('sha256', $signature),
            eventType: $eventType,
        );

        return response()->noContent();
    }

    private function header(Request $request, string $name): string
    {
        $value = $request->header($name);

        return is_string($value) ? trim($value) : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}