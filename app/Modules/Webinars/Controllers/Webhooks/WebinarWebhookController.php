<?php

namespace App\Modules\Webinars\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\PostEvent\HandleWebinarProviderWebhookEventAction;
use App\Modules\Webinars\Services\WebinarProviderManager;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class WebinarWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebinarProviderManager $webinarProviderManager,
        HandleWebinarProviderWebhookEventAction $handleWebinarProviderWebhookEventAction,
        WebhookInbox $webhookInbox,
        ?string $provider = null,
    ): Response {
        try {
            $event = $webinarProviderManager
                ->provider($provider)
                ->parseWebhook($request);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if ($event->event === 'endpoint.url_validation') {
            return response()->json($event->payload['response'] ?? []);
        }

        $webhookInbox->process(
            provider: $event->provider,
            providerEventId: $event->providerEventId,
            signatureFingerprint: $event->signatureFingerprint,
            eventType: $event->nativeEvent ?? $event->event,
            payload: $event->payload,
            processor: function () use (
                $event,
                $handleWebinarProviderWebhookEventAction,
            ): array {
                $handleWebinarProviderWebhookEventAction->execute($event);

                return ['http_status' => 204];
            },
        );

        return response()->noContent();
    }
}
