<?php

namespace App\Integrations\Webinars\Zoom;

use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ZoomEventService
{
    public function __construct(
        private readonly ZoomOAuthService $auth,
        private readonly ZoomAttendanceMapper $attendanceMapper,
    ) {}

    public function registerAttendee(
        WebinarProviderEventType $eventType,
        string $eventId,
        array $data,
    ): array {
        $response = $this->client()->post(
            sprintf('/%s/%s/registrants', $this->plural($eventType), rawurlencode($eventId)),
            [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '-',
            ],
        );

        $response->throw();

        return $response->json();
    }

    public function cancelRegistrant(
        WebinarProviderEventType $eventType,
        string $eventId,
        string $registrantId,
        ?string $occurrenceId = null,
    ): void {
        $response = $this->client()->delete(
            sprintf(
                '/%s/%s/registrants/%s',
                $this->plural($eventType),
                rawurlencode($eventId),
                rawurlencode($registrantId),
            ),
            array_filter([
                'occurrence_id' => $occurrenceId,
            ], fn (mixed $value): bool => filled($value)),
        );

        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    public function listPastParticipants(
        WebinarProviderEventType $eventType,
        string $eventId,
    ): ProviderAttendanceSnapshot {
        $participants = collect();
        $nextPageToken = null;
        $nonAuthoritativeReason = null;
        $seenPageTokens = [];

        do {
            $response = $this->client()->get(
                sprintf(
                    '/report/%s/%s/participants',
                    $this->plural($eventType),
                    rawurlencode($eventId),
                ),
                [
                    'page_size' => 300,
                    'next_page_token' => $nextPageToken,
                ],
            );

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)
                || ! array_key_exists('participants', $payload)
                || ! is_array($payload['participants'])
            ) {
                $nonAuthoritativeReason = 'invalid_provider_payload';
                break;
            }

            $pageParticipants = collect($payload['participants']);

            if ($pageParticipants->contains(
                fn (mixed $participant): bool => ! $this->validParticipant($participant),
            )) {
                $nonAuthoritativeReason = 'invalid_provider_participant_item';
            }

            $participants = $participants->merge(
                $pageParticipants
                    ->filter(fn (mixed $participant): bool => $this->validParticipant($participant))
                    ->map(fn (array $participant): array => $this->normalizeParticipant($participant)),
            );

            $rawNextPageToken = $payload['next_page_token'] ?? null;

            if ($rawNextPageToken !== null && ! is_string($rawNextPageToken)) {
                $nonAuthoritativeReason = 'invalid_provider_pagination_token';
                break;
            }

            if (filled($rawNextPageToken)
                && in_array($rawNextPageToken, $seenPageTokens, true)
            ) {
                $nonAuthoritativeReason = 'repeated_provider_pagination_token';
                break;
            }

            $nextPageToken = filled($rawNextPageToken) ? $rawNextPageToken : null;

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

    public function listEventsByTitle(
        WebinarProviderEventType $eventType,
        string $title,
    ): ProviderWebinarSnapshot {
        $events = collect();
        $nextPageToken = null;
        $nonAuthoritativeReason = null;
        $responseKey = $this->plural($eventType);
        $seenPageTokens = [];

        do {
            $response = $this->client()->get('/users/me/'.$responseKey, [
                'type' => 'scheduled',
                'page_size' => 100,
                'next_page_token' => $nextPageToken,
            ]);

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)
                || ! array_key_exists($responseKey, $payload)
                || ! is_array($payload[$responseKey])
            ) {
                $nonAuthoritativeReason = 'invalid_provider_payload';
                break;
            }

            $pageEvents = collect($payload[$responseKey]);

            if ($pageEvents->contains(fn (mixed $event): bool =>
                ! is_array($event)
                || blank($event['id'] ?? null)
                || blank($event['topic'] ?? null)
            )) {
                $nonAuthoritativeReason = 'invalid_provider_webinar_item';
            }

            $events = $events->merge($pageEvents->filter(
                fn (mixed $event): bool =>
                    is_array($event)
                    && filled($event['id'] ?? null)
                    && filled($event['topic'] ?? null),
            ));

            $rawNextPageToken = $payload['next_page_token'] ?? null;

            if ($rawNextPageToken !== null && ! is_string($rawNextPageToken)) {
                $nonAuthoritativeReason = 'invalid_provider_pagination_token';
                break;
            }

            if (filled($rawNextPageToken)
                && in_array($rawNextPageToken, $seenPageTokens, true)
            ) {
                $nonAuthoritativeReason = 'repeated_provider_pagination_token';
                break;
            }

            $nextPageToken = filled($rawNextPageToken) ? $rawNextPageToken : null;

            if ($nextPageToken !== null) {
                $seenPageTokens[] = $nextPageToken;
            }
        } while ($nextPageToken);

        $matchedEvents = $events
            ->filter(fn (array $event): bool => ($event['topic'] ?? null) === $title)
            ->map(fn (array $event): ProviderWebinarData => $this->normalizeEvent($event))
            ->values();

        if ($nonAuthoritativeReason !== null) {
            return ProviderWebinarSnapshot::nonAuthoritative(
                webinars: $matchedEvents,
                reason: $nonAuthoritativeReason,
            );
        }

        if ($matchedEvents->isEmpty()) {
            return ProviderWebinarSnapshot::nonAuthoritative(
                webinars: [],
                reason: 'no_exact_title_matches',
            );
        }

        return ProviderWebinarSnapshot::authoritative($matchedEvents);
    }

    public function getRecording(string $eventIdOrUuid): ?ProviderRecordingData
    {
        $response = $this->client()->get(
            '/meetings/'.$this->encodeRecordingIdentifier($eventIdOrUuid).'/recordings',
        );

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();
        $payload = $response->json();

        $recordingFile = collect($payload['recording_files'] ?? [])
            ->first(fn (mixed $file): bool =>
                is_array($file)
                && ($file['status'] ?? null) === 'completed'
                && ($file['file_type'] ?? null) === 'MP4'
                && filled($file['play_url'] ?? null)
            );

        return new ProviderRecordingData(
            playbackUrl: is_array($recordingFile)
                ? ($recordingFile['play_url'] ?? null)
                : null,
            playbackPasscode: $payload['recording_play_passcode']
                ?? $payload['password']
                ?? null,
            raw: is_array($payload) ? $payload : [],
        );
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->auth->getAccessToken())
            ->baseUrl((string) config('webinars.providers.zoom.base_url'));
    }

    private function plural(WebinarProviderEventType $eventType): string
    {
        return match ($eventType) {
            WebinarProviderEventType::Webinar => 'webinars',
            WebinarProviderEventType::Meeting => 'meetings',
        };
    }

    private function validParticipant(mixed $participant): bool
    {
        if (! is_array($participant)) {
            return false;
        }

        $registrantId = $participant['registrant_id'] ?? null;
        $email = $participant['user_email'] ?? $participant['email'] ?? null;

        return ((is_string($registrantId) || is_int($registrantId)) && filled($registrantId))
            || (is_string($email) && trim($email) !== '');
    }

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

    private function normalizeEvent(array $event): ProviderWebinarData
    {
        $startsAt = filled($event['start_time'] ?? null)
            ? Carbon::parse($event['start_time'])->utc()
            : null;
        $duration = filled($event['duration'] ?? null)
            ? (int) $event['duration']
            : null;

        return new ProviderWebinarData(
            externalId: (string) $event['id'],
            title: (string) $event['topic'],
            joinUrl: $event['join_url'] ?? null,
            registrationUrl: $event['registration_url'] ?? null,
            startsAt: $startsAt,
            endsAt: $startsAt && $duration
                ? $startsAt->copy()->addMinutes($duration)
                : null,
            timezone: $event['timezone'] ?? config('app.timezone', 'America/Chicago'),
            description: $event['agenda'] ?? null,
            meta: [
                'zoom_uuid' => $event['uuid'] ?? null,
            ],
        );
    }

    private function encodeRecordingIdentifier(string $identifier): string
    {
        if (str_starts_with($identifier, '/') || str_contains($identifier, '//')) {
            return rawurlencode(rawurlencode($identifier));
        }

        return rawurlencode($identifier);
    }
}