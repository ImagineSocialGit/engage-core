<?php

namespace App\Integrations\Webinars\Zoom;

use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use Illuminate\Http\Request;

class ZoomWebhookHandler
{
    private const PROVIDER = 'zoom';

    private const MEETING_TYPES = [
        1,
        2,
        3,
        4,
        7,
        8,
        10,
        100,
    ];

    private const WEBINAR_TYPES = [
        5,
        6,
        9,
    ];

    public function __construct(
        private readonly ZoomWebhookVerifier $verifier,
    ) {}

    public function parse(Request $request): ProviderWebhookEvent
    {
        $nativeEvent = trim((string) $request->input('event'));

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
            providerEventType: $this->providerEventType($nativeEvent, $request),
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

        return is_string($normalized) && trim($normalized) !== ''
            ? trim($normalized)
            : $event;
    }

    private function providerEventType(
        string $nativeEvent,
        Request $request,
    ): ?string {
        if (str_starts_with($nativeEvent, 'webinar.')) {
            return WebinarProviderEventType::Webinar->value;
        }

        if (str_starts_with($nativeEvent, 'meeting.')) {
            return WebinarProviderEventType::Meeting->value;
        }

        if ($nativeEvent !== 'recording.completed') {
            return null;
        }

        return $this->providerEventTypeFromZoomObjectType(
            $request->input('payload.object.type'),
        );
    }

    private function providerEventTypeFromZoomObjectType(mixed $value): ?string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === WebinarProviderEventType::Webinar->value) {
                return WebinarProviderEventType::Webinar->value;
            }

            if ($normalized === WebinarProviderEventType::Meeting->value) {
                return WebinarProviderEventType::Meeting->value;
            }
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $type = (int) $value;

        if (in_array($type, self::WEBINAR_TYPES, true)) {
            return WebinarProviderEventType::Webinar->value;
        }

        if (in_array($type, self::MEETING_TYPES, true)) {
            return WebinarProviderEventType::Meeting->value;
        }

        return null;
    }
}