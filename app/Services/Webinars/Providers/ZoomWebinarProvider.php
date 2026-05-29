<?php

namespace App\Services\Webinars\Providers;

use App\Contracts\Webinars\WebinarProvider;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Zoom\ZoomWebinarService;

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
        $registration->loadMissing('lead');

        $lead = $registration->lead;

        $response = $this->zoomWebinarService->registerAttendee($webinar->external_id, [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
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
