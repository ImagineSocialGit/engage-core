<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class EmitWebinarAutomationEventAction
{
    public function __construct(
        private readonly AutomationEventOutbox $outbox,
    ) {}

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
            'webinar',
            'webinar.webinarSeries',
        ]);

        $this->outbox->record(
            AutomationEventData::forSubject(
                eventKey: $eventKey,
                subject: $registration,
                contactId: $registration->contact_id,
                occurredAt: $occurredAt,
                payload: array_merge(
                    $this->registrationPayload($registration),
                    $this->eventPayload($eventKey, $payload),
                ),
                meta: array_merge([
                    'source_module' => 'webinars',
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ], $this->eventMeta($meta)),
            ),
            idempotencyKey: $this->idempotencyKey($eventKey, $registration),
        );
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

        $this->outbox->record(
            AutomationEventData::forSubject(
                eventKey: $eventKey,
                subject: $webinar,
                contactId: null,
                occurredAt: $occurredAt,
                payload: array_merge(
                    $this->webinarPayload($webinar),
                    $this->eventPayload($eventKey, $payload),
                ),
                meta: array_merge([
                    'source_module' => 'webinars',
                    'webinar_id' => $webinar->getKey(),
                    'webinar_slug' => $webinar->slug,
                ], $this->eventMeta($meta)),
            ),
            idempotencyKey: $this->idempotencyKey($eventKey, $webinar),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(WebinarRegistration $registration): array
    {
        return [
            'webinar_registration' => $this->compact([
                'id' => $registration->getKey(),
                'webinar_id' => $registration->webinar_id,
                'status' => $registration->status,
                'webinar_slug' => $registration->webinar_slug,
                'source' => $registration->source,
                'registered_at' => $registration->registered_at?->toISOString(),
                'attended_at' => $registration->attended_at?->toISOString(),
                'cancelled_at' => $registration->cancelled_at?->toISOString(),
            ]),
            'webinar' => $registration->webinar
                ? $this->webinarPayload($registration->webinar)['webinar']
                : [],
            'webinar_series' => $this->webinarSeriesPayload(
                $registration->webinar?->webinarSeries,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarPayload(Webinar $webinar): array
    {
        return [
            'webinar' => $this->compact([
                'id' => $webinar->getKey(),
                'webinar_series_id' => $webinar->webinar_series_id,
                'slug' => $webinar->slug,
                'starts_at' => $webinar->starts_at?->toISOString(),
                'ends_at' => $webinar->ends_at?->toISOString(),
                'playback_available' => filled($webinar->playback_url),
            ]),
            'webinar_series' => $this->webinarSeriesPayload(
                $webinar->webinarSeries,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarSeriesPayload(mixed $series): array
    {
        if (! $series) {
            return [];
        }

        return $this->compact([
            'id' => $series->getKey(),
            'slug' => $series->slug,
            'status' => $series->status,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function eventPayload(string $eventKey, array $payload): array
    {
        if (in_array($eventKey, $this->attendanceEventKeys(), true)) {
            $attendance = is_array($payload['attendance'] ?? null)
                ? $payload['attendance']
                : [];

            $normalized = $this->compact([
                'provider' => $this->boundedString($attendance['provider'] ?? null, 64),
                'status' => $this->boundedString($attendance['status'] ?? null, 64),
                'duration' => $this->integer($attendance['duration'] ?? null),
                'join_time' => $this->boundedString($attendance['join_time'] ?? null, 64),
                'leave_time' => $this->boundedString($attendance['leave_time'] ?? null, 64),
            ]);

            return $normalized === [] ? [] : ['attendance' => $normalized];
        }

        if ($eventKey === 'webinar.cancelled') {
            $cancellation = is_array($payload['cancellation'] ?? null)
                ? $payload['cancellation']
                : [];

            $normalized = $this->compact([
                'source' => $this->boundedString($cancellation['source'] ?? null, 191),
                'resolved_from_registration_id' => $this->integer(
                    $cancellation['resolved_from_registration_id'] ?? null,
                ),
                'canonical_registration_id' => $this->integer(
                    $cancellation['canonical_registration_id'] ?? null,
                ),
                'traversed_registration_ids' => $this->integerList(
                    $cancellation['traversed_registration_ids'] ?? null,
                ),
            ]);

            return $normalized === [] ? [] : ['cancellation' => $normalized];
        }

        if (in_array($eventKey, $this->postEventKeys(), true)) {
            $eventPayload = [];
            $provider = is_array($payload['provider'] ?? null)
                ? $payload['provider']
                : [];
            $normalizedProvider = $this->compact([
                'key' => $this->boundedString($provider['key'] ?? null, 64),
            ]);

            if ($normalizedProvider !== []) {
                $eventPayload['provider'] = $normalizedProvider;
            }

            $postEvent = is_array($payload['post_event'] ?? null)
                ? $payload['post_event']
                : [];
            $normalizedPostEvent = $this->compact([
                'event' => $this->boundedString($postEvent['event'] ?? null, 191),
            ]);

            if ($normalizedPostEvent !== []) {
                $eventPayload['post_event'] = $normalizedPostEvent;
            }

            return $eventPayload;
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function attendanceEventKeys(): array
    {
        return [
            $this->configuredEventKey(
                'webinars.post_event.automation_events.attended.event_key',
                'webinar.attended',
            ),
            $this->configuredEventKey(
                'webinars.post_event.automation_events.missed.event_key',
                'webinar.missed',
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function postEventKeys(): array
    {
        return [
            $this->configuredEventKey(
                'webinars.post_event.automation_events.webinar_ended.event_key',
                'webinar.ended',
            ),
            $this->configuredEventKey(
                'webinars.post_event.automation_events.replay_available.event_key',
                'webinar.replay_available',
            ),
        ];
    }

    private function configuredEventKey(string $path, string $fallback): string
    {
        $eventKey = config($path, $fallback);

        return is_string($eventKey) && trim($eventKey) !== ''
            ? trim($eventKey)
            : $fallback;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function eventMeta(array $meta): array
    {
        return $this->compact([
            'source' => $this->boundedString($meta['source'] ?? null, 191),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function compact(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function boundedString(mixed $value, int $maximumLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maximumLength);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, int>|null
     */
    private function integerList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $integers = [];

        foreach ($value as $item) {
            $integer = $this->integer($item);

            if ($integer !== null) {
                $integers[$integer] = $integer;
            }

            if (count($integers) >= 50) {
                break;
            }
        }

        return array_values($integers);
    }

    private function idempotencyKey(string $eventKey, Model $subject): string
    {
        return 'webinars:'.hash('sha256', implode('|', [
            trim($eventKey),
            $subject->getMorphClass(),
            (string) $subject->getKey(),
        ]));
    }
}