<?php

namespace App\Modules\Webinars\Controllers\Webhooks;

use App\Modules\Webinars\Actions\PostEvent\HandleWebinarProviderWebhookEventAction;
use App\Http\Controllers\Controller;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class WebinarWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebinarProviderManager $webinarProviderManager,
        HandleWebinarProviderWebhookEventAction $handleWebinarProviderWebhookEventAction,
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

        $handleWebinarProviderWebhookEventAction->execute($event);

        return response()->noContent();
    }
}