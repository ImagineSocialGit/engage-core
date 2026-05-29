<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Sms\HandleTwilioInboundSmsWebhookAction;
use App\Http\Controllers\Controller;
use App\Services\Messaging\TwilioWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioSmsWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TwilioWebhookVerifier $verifier,
        HandleTwilioInboundSmsWebhookAction $handleTwilioInboundSmsWebhookAction,
    ): Response {
        if (! $verifier->isValid($request)) {
            abort(403);
        }

        return $this->twiml(
            $handleTwilioInboundSmsWebhookAction->handle($request),
        );
    }

    private function twiml(?string $message = null): Response
    {
        $body = $message
            ? '<Response><Message>'.e($message).'</Message></Response>'
            : '<Response></Response>';

        return response($body, 200)
            ->header('Content-Type', 'text/xml');
    }
}