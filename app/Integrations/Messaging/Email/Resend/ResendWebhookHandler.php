<?php

namespace App\Integrations\Messaging\Email\Resend;

use App\Modules\InboundMessaging\Actions\Email\HandleInboundEmailWebhookAction;
use App\Modules\InboundMessaging\Contracts\Email\EmailWebhookHandler;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookPayload;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResendWebhookHandler implements EmailWebhookHandler
{
    public function __construct(
        private readonly ResendWebhookVerifier $verifier,
        private readonly HandleInboundEmailWebhookAction $handleInboundEmailWebhookAction,
    ) {}

    public function handle(EmailWebhookPayload $payload): void
    {
        if (! $this->verifier->isValid(
            payload: $payload->rawBody ?? '',
            headers: [
                'svix-id' => $payload->header('svix-id'),
                'svix-timestamp' => $payload->header('svix-timestamp'),
                'svix-signature' => $payload->header('svix-signature'),
            ],
        )) {
            throw new HttpResponseException(response(status: 403));
        }

        $this->handleInboundEmailWebhookAction->handle(
            event: $payload->payload,
            sourceEventId: $payload->header('svix-id'),
            provider: MessageSuppression::PROVIDER_RESEND,
        );
    }
}