<?php

namespace App\Integrations\Webinars\Zoom;

use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class ZoomWebinarService
{
    public function __construct(
        private readonly ZoomOAuthService $auth,
        private readonly ZoomAttendanceMapper $attendanceMapper,
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

        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    public function listPastWebinarParticipants(string $webinarId): ProviderAttendanceSnapshot
    {
        return $this->fetchPastWebinarParticipants($webinarId);
    }

    private function fetchPastWebinarParticipants(string $webinarId): ProviderAttendanceSnapshot
    {
        $participants = collect();
        $nextPageToken = null;
        $nonAuthoritativeReason = null;
        $seenPageTokens = [];

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

            if (
                ! is_array($payload)
                || ! array_key_exists('participants', $payload)
                || ! is_array($payload['participants'])
            ) {
                $nonAuthoritativeReason = 'invalid_provider_payload';

                break;
            }

            $pageParticipants = collect($payload['participants']);

            if ($pageParticipants->contains(
                fn (mixed $participant): bool => ! $this->validParticipant($participant)
            )) {
                $nonAuthoritativeReason = 'invalid_provider_participant_item';
            }

            $participants = $participants->merge(
                $pageParticipants
                    ->filter(fn (mixed $participant): bool => $this->validParticipant($participant))
                    ->map(fn (array $participant): array => $this->normalizeParticipant($participant))
            );

            $rawNextPageToken = $payload['next_page_token'] ?? null;

            if ($rawNextPageToken !== null && ! is_string($rawNextPageToken)) {
                $nonAuthoritativeReason = 'invalid_provider_pagination_token';

                break;
            }

            if (
                filled($rawNextPageToken)
                && in_array($rawNextPageToken, $seenPageTokens, true)
            ) {
                $nonAuthoritativeReason = 'repeated_provider_pagination_token';

                break;
            }

            $nextPageToken = filled($rawNextPageToken)
                ? $rawNextPageToken
                : null;

            if ($nextPageToken !== null) {
                $seenPageTokens[] = $nextPageToken;
            }
        } while ($nextPageToken);

        $records = $this->attendanceMapper->map($participants->values());

        if ($nonAuthoritativeReason !== null) {
            return ProviderAttendanceSnapshot::nonAuthoritative(
                records: $records,
                reason: $nonAuthoritativeReason,
            );
        }

        if ($records->isEmpty()) {
            return ProviderAttendanceSnapshot::nonAuthoritative(
                records: [],
                reason: 'no_participant_records',
            );
        }

        return ProviderAttendanceSnapshot::authoritative($records);
    }

    private function validParticipant(mixed $participant): bool
    {
        if (! is_array($participant)) {
            return false;
        }

        $registrantId = $participant['registrant_id'] ?? null;
        $email = $participant['user_email'] ?? $participant['email'] ?? null;

        return (
            (is_string($registrantId) || is_int($registrantId))
            && filled($registrantId)
        ) || (
            is_string($email)
            && trim($email) !== ''
        );
    }

    /**
     * @param array<string, mixed> $participant
     * @return array<string, mixed>
     */
    private function normalizeParticipant(array $participant): array
    {
        return [
            'registrant_id' => $participant['registrant_id'] ?? null,
            'user_id' => $participant['id'] ?? null,
            'name' => $participant['name'] ?? null,
            'email' => is_string($participant['user_email'] ?? null)
                ? mb_strtolower(trim($participant['user_email']))
                : (is_string($participant['email'] ?? null)
                    ? mb_strtolower(trim($participant['email']))
                    : null),
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
