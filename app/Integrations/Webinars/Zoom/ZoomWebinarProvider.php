<?php

namespace App\Integrations\Webinars\Zoom;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;

class ZoomWebinarProvider implements WebinarProvider
{
    public function __construct(
        private readonly ZoomWebinarService $zoomWebinarService,
        private readonly ZoomWebhookHandler $zoomWebhookHandler,
    ) {}

    public function key(): string
    {
        return 'zoom';
    }

    public function name(): string
    {
        return 'Zoom';
    }

    public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): ProviderRegistrationData
    {
        $registration->loadMissing('contact');

        $contact = $registration->contact;

        $response = $this->zoomWebinarService->registerAttendee($webinar->external_id, [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);

        return new ProviderRegistrationData(
            provider: $this->key(),
            registrantId: $response['registrant_id'] ?? $response['id'] ?? null,
            joinUrl: $response['join_url'] ?? null,
            raw: $response,
        );
    }

    public function cancelRegistration(WebinarRegistration $registration): void
    {
        $registration->loadMissing('webinar');

        $webinar = $registration->webinar;

        $registrantId = data_get($registration->meta, 'provider.data.registrant_id')
            ?? data_get($registration->meta, 'provider.registrant_id')
            ?? data_get($registration->meta, 'provider.id');

        if (! $webinar || blank($webinar->external_id) || blank($registrantId)) {
            return;
        }

        $this->zoomWebinarService->cancelRegistrant(
            webinarId: (string) $webinar->external_id,
            registrantId: (string) $registrantId,
            occurrenceId: data_get($registration->meta, 'provider.data.occurrence_id')
                ?? data_get($registration->meta, 'provider.data.raw.occurrence_id')
                ?? data_get($registration->meta, 'provider.occurrence_id')
                ?? data_get($registration->meta, 'provider.raw.occurrence_id')
        );
    }

    public function listWebinarsByTitle(string $title): iterable
    {
        return $this->zoomWebinarService->listWebinarsByTitle($title);
    }

    public function parseWebhook(Request $request): ProviderWebhookEvent
    {
        return $this->zoomWebhookHandler->parse($request);
    }

    public function listAttendanceRecords(Webinar $webinar): ProviderAttendanceSnapshot
    {
        return $this->zoomWebinarService->listPastWebinarParticipants($webinar->external_id);
    }

    public function getRecording(Webinar $webinar): ?ProviderRecordingData
    {
        return $this->zoomWebinarService->getWebinarRecording(
            webinarIdOrUuid: $this->recordingLookupId($webinar),
        );
    }

    private function recordingLookupId(Webinar $webinar): string
    {
        $uuid = data_get($webinar->meta, 'provider.data.zoom_uuid')
            ?? data_get($webinar->meta, 'zoom_uuid');

        return filled($uuid)
            ? (string) $uuid
            : (string) $webinar->external_id;
    }
}
