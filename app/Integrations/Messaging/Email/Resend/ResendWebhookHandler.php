<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\InboundMessaging\Actions\Email\HandleInboundEmailWebhookAction;
use App\Modules\InboundMessaging\Contracts\Email\EmailWebhookHandler;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookPayload;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResendWebhookHandler implements EmailWebhookHandler
{
    public function __construct(
        private readonly ResendWebhookVerifier $verifier,
        private readonly HandleInboundEmailWebhookAction $handleInboundEmailWebhookAction,
        private readonly WebhookInbox $webhookInbox,
    ) {}

    public function handle(EmailWebhookPayload $payload): void
    {
        $eventId = $this->stringHeader($payload, 'svix-id');
        $signature = $this->stringHeader($payload, 'svix-signature');

        if (! $this->verifier->isValid(
            payload: $payload->rawBody ?? '',
            headers: [
                'svix-id' => $eventId,
                'svix-timestamp' => $payload->header('svix-timestamp'),
                'svix-signature' => $signature,
            ],
        )) {
            throw new HttpResponseException(response(status: 403));
        }

        $this->webhookInbox->process(
            provider: MessageSuppression::PROVIDER_RESEND,
            providerEventId: $eventId,
            signatureFingerprint: hash('sha256', $signature),
            eventType: $payload->eventType(),
            payload: $payload->payload,
            processor: function () use ($payload, $eventId): array {
                $this->handleInboundEmailWebhookAction->handle(
                    event: $payload->payload,
                    sourceEventId: $eventId,
                    provider: MessageSuppression::PROVIDER_RESEND,
                );

                return ['http_status' => 204];
            },
        );
    }

    private function stringHeader(
        EmailWebhookPayload $payload,
        string $name,
    ): string {
        $value = $payload->header($name);

        return is_string($value) ? trim($value) : '';
    }
}
