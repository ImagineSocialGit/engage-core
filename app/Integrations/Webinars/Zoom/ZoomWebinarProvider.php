<?php

namespace App\Integrations\Webinars\Zoom;

use App\Contracts\Webinars\WebinarProvider;
use App\Integrations\Webinars\Zoom\ZoomWebinarService;
use App\Models\Webinar;
use App\Models\WebinarRegistration;

class ZoomWebinarProvider implements WebinarProvider
{
    public function __construct(
        private readonly ZoomWebinarService $zoomWebinarService,
    ) {}

    public function name(): string
    {
        return 'zoom';
    }

    public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): array
    {
        $registration->loadMissing('contact');

        $contact = $registration->contact;

        $response = $this->zoomWebinarService->registerAttendee($webinar->external_id, [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);

        return [
            'name' => $this->name(),
            'data' => [
                'registrant_id' => $response['registrant_id'] ?? $response['id'] ?? null,
                'join_url' => $response['join_url'] ?? null,
            ],
            'raw' => $response,
        ];
    }
}
