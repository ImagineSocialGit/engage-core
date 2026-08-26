<?php

namespace App\Modules\InboundMessaging\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\InboundMessaging\Actions\Sms\HandleInboundSmsWebhookAction;
use App\Modules\InboundMessaging\Services\Sms\SmsWebhookHandlerResolver;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class SmsWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        SmsWebhookHandlerResolver $resolver,
        HandleInboundSmsWebhookAction $handleInboundSmsWebhookAction,
        WebhookInbox $webhookInbox,
    ): Response {
        $handler = $resolver->resolve($provider);

        if (! $handler->isValid($request)) {
            abort(403);
        }

        $payload = $handler->payloadFrom($request);
        $callbackIdentity = $payload->providerEventId
            ?? $payload->providerMessageId;

        if (! is_string($callbackIdentity) || trim($callbackIdentity) === '') {
            throw new InvalidArgumentException(
                'SMS webhook requires a stable provider event or message identifier.',
            );
        }

        $receipt = $webhookInbox->process(
            provider: $handler->provider(),
            providerEventId: trim($callbackIdentity),
            eventType: $payload->eventType,
            payload: $payload->raw,
            processor: function () use (
                $handleInboundSmsWebhookAction,
                $payload,
            ): ?array {
                $responseMessage = $handleInboundSmsWebhookAction->handle($payload);

                return $responseMessage !== null
                    ? ['response_message' => $responseMessage]
                    : null;
            },
        );

        $responseMessage = data_get($receipt->outcome, 'response_message');

        return $handler->response(
            is_string($responseMessage) ? $responseMessage : null,
        );
    }
}