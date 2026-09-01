<?php

namespace App\Modules\Messaging\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Messaging\Email\Resend\ResendMessageEventWebhookHandler;
use App\Integrations\Messaging\Sms\Telnyx\TelnyxMessageEventWebhookHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MessageEventWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        ResendMessageEventWebhookHandler $resend,
        TelnyxMessageEventWebhookHandler $telnyx,
    ): Response {
        return match ($request->route()?->getName().':'.$provider) {
            'webhooks.message-events.email:resend' => $resend->handle($request),
            'webhooks.message-events.sms:telnyx' => $telnyx->handle($request),
            default => abort(404),
        };
    }
}