<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Messaging\Sms\HandleInboundSmsWebhookAction;
use App\Http\Controllers\Controller;
use App\Services\Messaging\Sms\SmsWebhookHandlerResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SmsWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        SmsWebhookHandlerResolver $resolver,
        HandleInboundSmsWebhookAction $handleInboundSmsWebhookAction,
    ): Response {
        $handler = $resolver->resolve($provider);

        if (! $handler->isValid($request)) {
            abort(403);
        }

        $responseMessage = $handleInboundSmsWebhookAction->handle(
            $handler->payloadFrom($request),
        );

        return $handler->response($responseMessage);
    }
}