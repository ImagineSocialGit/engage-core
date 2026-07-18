<?php

namespace App\Integrations\Webinars\Zoom;

use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Support\Caching\CacheKey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ZoomWebinarService
{
    public function __construct(
        private readonly ZoomOAuthService $auth
    ) {}

    protected function client()
    {
        return Http::withToken($this->auth->getAccessToken())
            ->baseUrl(config('webinars.providers.zoom.base_url'));
    }

    public function registerAttendee(string $webinarId, array $data): array
    {
        $response = $this->client()->post(
            "/webinars/{$webinarId}/registrants",
            [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '-',
            ]
        );

        $response->throw();

        return $response->json();
    }

    public function cancelRegistrant(
        string $webinarId,
        string $registrantId,
        ?string $occurrenceId = null
    ): void {
        $response = $this->client()->delete(
            "/webinars/{$webinarId}/registrants/".rawurlencode($registrantId),
            array_filter([
                'occurrence_id' => $occurrenceId,
            ], fn (mixed $value): bool => filled($value))
        );

        $response->throw();
    }

    public function listPastWebinarParticipants(string $webinarId): Collection
    {
        return Cache::remember(
            CacheKey::externalApiResponse('zoom', 'past-webinar-participants', $webinarId),
            (int) config('cache-keys.ttl.external_api_response_seconds'),
            fn () => $this->fetchPastWebinarParticipants($webinarId)
        );
    }

    private function fetchPastWebinarParticipants(string $webinarId): Collection
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

    public function listWebinarsByTitle(string $title): ProviderWebinarSnapshot
    {
        $webinars = collect();
        $nextPageToken = null;
        $nonAuthoritativeReason = null;

        do {
            $response = $this->client()->get('/users/me/webinars', [
                'page_size' => 100,
                'next_page_token' => $nextPageToken,
            ]);

            $response->throw();

            $payload = $response->json();

            if (
                ! is_array($payload)
                || ! array_key_exists('webinars', $payload)
                || ! is_array($payload['webinars'])
            ) {
                $nonAuthoritativeReason = 'invalid_provider_payload';

                break;
            }

            $pageWebinars = collect($payload['webinars']);

            if ($pageWebinars->contains(fn (mixed $webinar): bool =>
                ! is_array($webinar)
                || blank($webinar['id'] ?? null)
                || blank($webinar['topic'] ?? null)
            )) {
                $nonAuthoritativeReason = 'invalid_provider_webinar_item';
            }

            $webinars = $webinars->merge($pageWebinars->filter(
                fn (mixed $webinar): bool =>
                    is_array($webinar)
                    && filled($webinar['id'] ?? null)
                    && filled($webinar['topic'] ?? null)
            ));

            $rawNextPageToken = $payload['next_page_token'] ?? null;

            if ($rawNextPageToken !== null && ! is_string($rawNextPageToken)) {
                $nonAuthoritativeReason = 'invalid_provider_pagination_token';

                break;
            }

            $nextPageToken = filled($rawNextPageToken)
                ? $rawNextPageToken
                : null;
        } while ($nextPageToken);

        $matchedWebinars = $webinars
            ->filter(fn (array $webinar) => ($webinar['topic'] ?? null) === $title)
            ->map(fn (array $webinar) => $this->normalizeWebinar($webinar))
            ->values();

        if ($nonAuthoritativeReason !== null) {
            return ProviderWebinarSnapshot::nonAuthoritative(
                webinars: $matchedWebinars,
                reason: $nonAuthoritativeReason,
            );
        }

        if ($matchedWebinars->isEmpty()) {
            return ProviderWebinarSnapshot::nonAuthoritative(
                webinars: [],
                reason: 'no_exact_title_matches',
            );
        }

        return ProviderWebinarSnapshot::authoritative($matchedWebinars);
    }

    public function getWebinarRecording(string $webinarIdOrUuid): ?ProviderRecordingData
    {
        $response = $this->client()->get(
            '/meetings/'.$this->encodeRecordingIdentifier($webinarIdOrUuid).'/recordings'
        );

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $payload = $response->json();

        $recordingFile = collect($payload['recording_files'] ?? [])
            ->first(fn (array $file) =>
                ($file['status'] ?? null) === 'completed'
                && ($file['file_type'] ?? null) === 'MP4'
                && filled($file['play_url'] ?? null)
            );

        if (! $recordingFile) {
            return new ProviderRecordingData(
                playbackUrl: null,
                playbackPasscode: $payload['recording_play_passcode']
                    ?? $payload['password']
                    ?? null,
                raw: $payload,
            );
        }

        return new ProviderRecordingData(
            playbackUrl: $recordingFile['play_url'],
            playbackPasscode: $payload['recording_play_passcode']
                ?? $payload['password']
                ?? null,
            raw: $payload,
        );
    }

    private function encodeRecordingIdentifier(string $identifier): string
    {
        if (str_starts_with($identifier, '/') || str_contains($identifier, '//')) {
            return rawurlencode(rawurlencode($identifier));
        }

        return rawurlencode($identifier);
    }

    protected function normalizeWebinar(array $webinar): ProviderWebinarData
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

        return new ProviderWebinarData(
            externalId: (string) $webinar['id'],
            title: $webinar['topic'],
            joinUrl: $webinar['join_url'] ?? null,
            registrationUrl: $webinar['registration_url'] ?? null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $webinar['timezone'] ?? config('app.timezone', 'America/Chicago'),
            description: $webinar['agenda'] ?? null,
            meta: [
                'zoom_uuid' => $webinar['uuid'] ?? null,
            ],
        );
    }
}

