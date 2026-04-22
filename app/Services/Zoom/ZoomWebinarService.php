<?php

namespace App\Services\Zoom;

use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    public function listPastWebinarParticipants(string $webinarId): Collection
    {
        $participants = collect();
        $nextPageToken = null;

        do {
            $response = $this->client()->get(
                "/report/webinars/{$webinarId}/participants",
                [
                    'page_size' => 300,
                    'next_page_token' => $nextPageToken,
                ]
            );

            $response->throw();

            $payload = $response->json();

            $participants = $participants->merge(
                collect($payload['participants'] ?? [])->map(function (array $participant) {
                    return [
                        'registrant_id' => $participant['registrant_id'] ?? null,
                        'user_id' => $participant['id'] ?? null,
                        'name' => $participant['name'] ?? null,
                        'email' => isset($participant['user_email'])
                            ? mb_strtolower(trim($participant['user_email']))
                            : null,
                        'join_time' => filled($participant['join_time'] ?? null)
                            ? Carbon::parse($participant['join_time'])->utc()
                            : null,
                        'leave_time' => filled($participant['leave_time'] ?? null)
                            ? Carbon::parse($participant['leave_time'])->utc()
                            : null,
                        'duration' => isset($participant['duration'])
                            ? (int) $participant['duration']
                            : null,
                        'raw' => $participant,
                    ];
                })
            );

            $nextPageToken = $payload['next_page_token'] ?: null;
        } while ($nextPageToken);

        return $participants->values();
    }

    public function listWebinarsByTitle(string $title): Collection
    {
        $webinars = collect();
        $nextPageToken = null;

        do {
            $response = $this->client()->get('/users/me/webinars', [
                'page_size' => 100,
                'next_page_token' => $nextPageToken,
            ]);

            $response->throw();

            $payload = $response->json();

            $webinars = $webinars->merge(
                collect($payload['webinars'] ?? [])
            );

            $nextPageToken = $payload['next_page_token'] ?: null;
        } while ($nextPageToken);

        return $webinars
            ->filter(fn (array $webinar) => ($webinar['topic'] ?? null) === $title)
            ->map(fn (array $webinar) => $this->normalizeWebinar($webinar))
            ->values();
    }

    protected function normalizeWebinar(array $webinar): array
    {
        $startsAt = filled($webinar['start_time'] ?? null)
            ? Carbon::parse($webinar['start_time'])->utc()
            : null;

        $duration = filled($webinar['duration'] ?? null)
            ? (int) $webinar['duration']
            : null;

        $endsAt = $startsAt && $duration
            ? $startsAt->copy()->addMinutes($duration)
            : null;

        return [
            'external_id' => (string) $webinar['id'],
            'title' => $webinar['topic'],
            'join_url' => $webinar['join_url'] ?? null,
            'registration_url' => $webinar['registration_url'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $webinar['timezone'] ?? config('app.timezone', 'America/Chicago'),
            'description' => $webinar['agenda'] ?? null,
            'meta' => [
                'zoom_uuid' => $webinar['uuid'] ?? null,
            ],
        ];
    }
}