<?php

namespace App\Integrations\Webinars\Zoom;

use App\Modules\Webinars\Data\ProviderWebhookEvent;
use Illuminate\Http\Request;

class ZoomWebhookHandler
{
    private const PROVIDER = 'zoom';

    public function __construct(
        private readonly ZoomWebhookVerifier $verifier,
    ) {}

    public function parse(Request $request): ProviderWebhookEvent
    {
        $nativeEvent = (string) $request->input('event');

        if ($nativeEvent === 'endpoint.url_validation') {
            return new ProviderWebhookEvent(
                provider: self::PROVIDER,
                event: 'endpoint.url_validation',
                nativeEvent: $nativeEvent,
                payload: [
                    'response' => $this->verifier->urlValidationResponse($request),
                ],
            );
        }

        if (! $this->verifier->hasValidSignature($request)) {
            abort(401);
        }

        return new ProviderWebhookEvent(
            provider: self::PROVIDER,
            event: $this->normalizeEvent($nativeEvent),
            externalWebinarId: filled($request->input('payload.object.id'))
                ? (string) $request->input('payload.object.id')
                : null,
            externalWebinarUuid: filled($request->input('payload.object.uuid'))
                ? (string) $request->input('payload.object.uuid')
                : null,
            nativeEvent: $nativeEvent,
            payload: $request->all(),
            signatureFingerprint: hash(
                'sha256',
                (string) $request->header('x-zm-signature'),
            ),
        );
    }

    private function normalizeEvent(string $event): string
    {
        $events = config('webinars.providers.zoom.webhook_events', []);

        if (! is_array($events)) {
            return $event;
        }

        $normalized = $events[$event] ?? null;

        return is_string($normalized) && $normalized !== ''
            ? $normalized
            : $event;
    }
}
