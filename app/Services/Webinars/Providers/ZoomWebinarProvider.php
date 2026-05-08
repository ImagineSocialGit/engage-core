<?php

namespace App\Services\Webinars\Providers;

use App\Contracts\Webinars\WebinarProvider;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Zoom\ZoomWebinarService;

class ZoomWebinarProvider implements WebinarProvider
{
    public function __construct(
        protected ZoomWebinarService $zoomWebinarService,
    ) {}

    public function name(): string
    {
        return 'zoom';
    }

    public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): array
    {
        $response = $this->zoomWebinarService->registerAttendee($webinar->external_id, [
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'email' => $registration->email,
            'phone' => $registration->phone,
        ]);

        return [
            'name' => $this->name(),
            'registrant_id' => $response['registrant_id'] ?? $response['id'] ?? null,
            'join_url' => $response['join_url'] ?? null,
            'raw' => $response,
        ];
    }
}
