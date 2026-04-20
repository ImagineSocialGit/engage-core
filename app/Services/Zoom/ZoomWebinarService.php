<?php

namespace App\Services\Zoom;

use Illuminate\Support\Facades\Http;

class ZoomWebinarService
{
    public function __construct(
        protected ZoomOAuthService $auth
    ) {}

    protected function client()
    {
        return Http::withToken($this->auth->getAccessToken())
            ->baseUrl(config('services.zoom.base_url'));
    }

    public function registerAttendee(string $webinarId, array $data): array
    {
        $response = $this->client()->post(
            "/webinars/{$webinarId}/registrants",
            [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
            ]
        );

        $response->throw();

        return $response->json();
    }
}