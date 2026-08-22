<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\InboundMessaging\Actions\Email\HandleInboundEmailWebhookAction;
use App\Modules\InboundMessaging\Actions\Email\RecordInboundEmailAction;
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
        private readonly RecordInboundEmailAction $recordInboundEmailAction,
        private readonly ResendReceivedEmailClient $receivedEmailClient,
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
                if ($payload->eventType() === 'email.received') {
                    $emailId = $payload->data('email_id');

                    if (! is_string($emailId) || trim($emailId) === '') {
                        throw new \RuntimeException(
                            'Resend email.received webhook did not contain data.email_id.',
                        );
                    }

                    $received = $this->receivedEmailClient->retrieve(trim($emailId));
                    $to = is_array($received['to'] ?? null)
                        ? $received['to']
                        : (is_array($payload->data('to')) ? $payload->data('to') : []);

                    $this->recordInboundEmailAction->handle(
                        provider: MessageSuppression::PROVIDER_RESEND,
                        providerEventId: $eventId,
                        providerMessageId: trim($emailId),
                        from: is_string($received['from'] ?? null)
                            ? $received['from']
                            : $payload->data('from'),
                        toAddresses: $to,
                        text: is_string($received['text'] ?? null) ? $received['text'] : null,
                        html: is_string($received['html'] ?? null) ? $received['html'] : null,
                        subject: is_string($received['subject'] ?? null)
                            ? $received['subject']
                            : $payload->data('subject'),
                        messageId: is_string($received['message_id'] ?? null)
                            ? $received['message_id']
                            : $payload->data('message_id'),
                        receivedAt: $received['created_at']
                            ?? $payload->data('created_at')
                            ?? now(),
                    );

                    return ['http_status' => 204];
                }

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