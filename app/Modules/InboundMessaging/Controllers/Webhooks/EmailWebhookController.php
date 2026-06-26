<?php

namespace App\Modules\InboundMessaging\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookHandlerResolver;
use App\Modules\InboundMessaging\Services\Email\EmailWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

class EmailWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        EmailWebhookHandlerResolver $resolver,
    ): Response {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400);
        }

        if (! is_array($payload)) {
            abort(400);
        }

        $resolver->resolve($provider)->handle(
            new EmailWebhookPayload(
                provider: $provider,
                payload: $payload,
                headers: [
                    'svix-id' => $request->header('svix-id'),
                    'svix-timestamp' => $request->header('svix-timestamp'),
                    'svix-signature' => $request->header('svix-signature'),
                ],
                rawBody: $request->getContent(),
            )
        );

        return response(status: 204);
    }
}