<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Email\HandleResendWebhookAction;
use App\Http\Controllers\Controller;
use App\Integrations\Messaging\Email\Resend\ResendWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

class ResendWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ResendWebhookVerifier $verifier,
        HandleResendWebhookAction $handleResendWebhookAction,
    ): Response {
        if (! $verifier->isValid($request)) {
            abort(403);
        }

        try {
            $event = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400);
        }

        if (! is_array($event)) {
            abort(400);
        }

        $handleResendWebhookAction->handle(
            event: $event,
            sourceEventId: $request->header('svix-id'),
        );

        return response(status: 204);
    }
}