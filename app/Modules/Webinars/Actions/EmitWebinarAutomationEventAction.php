<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Carbon\CarbonInterface;

class EmitWebinarAutomationEventAction
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function forRegistration(
        string $eventKey,
        WebinarRegistration $registration,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        array $meta = [],
    ): void {
        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: $eventKey,
                subject: $registration,
                contactId: $registration->contact_id,
                occurredAt: $occurredAt,
                payload: array_replace_recursive(
                    $this->registrationPayload($registration),
                    $payload,
                ),
                meta: array_replace_recursive([
                    'source_module' => 'webinars',
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ], $meta),
            ),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function forWebinar(
        string $eventKey,
        Webinar $webinar,
        ?CarbonInterface $occurredAt = null,
        array $payload = [],
        array $meta = [],
    ): void {
        $webinar->loadMissing('webinarSeries');

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: $eventKey,
                subject: $webinar,
                contactId: null,
                occurredAt: $occurredAt,
                payload: array_replace_recursive(
                    $this->webinarPayload($webinar),
                    $payload,
                ),
                meta: array_replace_recursive([
                    'source_module' => 'webinars',
                    'webinar_id' => $webinar->getKey(),
                    'webinar_slug' => $webinar->slug,
                ], $meta),
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(WebinarRegistration $registration): array
    {
        return [
            'webinar_registration' => [
                'id' => $registration->getKey(),
                'status' => $registration->status,
                'webinar_slug' => $registration->webinar_slug,
                'source' => $registration->source,
                'registered_at' => $registration->registered_at?->toISOString(),
                'attended_at' => $registration->attended_at?->toISOString(),
                'cancelled_at' => $registration->cancelled_at?->toISOString(),
                'meta' => $registration->meta ?? [],
            ],
            'webinar' => $registration->webinar
                ? $this->webinarPayload($registration->webinar)['webinar']
                : [],
            'webinar_series' => $registration->webinar?->webinarSeries?->toArray() ?? [],
            'contact' => $registration->contact?->toArray() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarPayload(Webinar $webinar): array
    {
        return [
            'webinar' => [
                'id' => $webinar->getKey(),
                'slug' => $webinar->slug,
                'title' => $webinar->title,
                'platform' => $webinar->platform,
                'external_id' => $webinar->external_id,
                'starts_at' => $webinar->starts_at?->toISOString(),
                'ends_at' => $webinar->ends_at?->toISOString(),
                'timezone' => $webinar->timezone,
                'playback_url' => $webinar->playback_url,
                'playback_passcode' => $webinar->playback_passcode,
                'playback_token' => $webinar->playback_token,
                'playback_available' => filled($webinar->playback_url),
                'meta' => $webinar->meta ?? [],
            ],
            'playback' => [
                'available' => filled($webinar->playback_url),
                'url' => $webinar->playback_url,
                'passcode' => $webinar->playback_passcode,
                'token' => $webinar->playback_token,
            ],
            'webinar_series' => $webinar->webinarSeries?->toArray() ?? [],
        ];
    }
}
