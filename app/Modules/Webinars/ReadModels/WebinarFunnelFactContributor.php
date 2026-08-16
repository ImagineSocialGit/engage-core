<?php

namespace App\Modules\Webinars\ReadModels;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Reporting\Contracts\ReportingProjectionFactContributor;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class WebinarFunnelFactContributor implements ReportingProjectionFactContributor
{
    public const CONTRIBUTOR_KEY = 'webinar_funnel';

    public const FACT_KEY = 'webinar.registration';

    public const FACT_VERSION = 1;

    public const QUESTION_FACT_KEY = 'webinar.question_response';

    private const CONFIRMATION_INTENT = 'webinar.registration.confirmation';

    public function key(): string
    {
        return self::CONTRIBUTOR_KEY;
    }

    /** @return iterable<int, ReportingProjectionFact> */
    public function facts(ReportingProjectionWindow $window): iterable
    {
        $registrations = WebinarRegistration::query()
            ->with([
                'webinar',
                'webinar.webinarSeries',
                'responses',
            ])
            ->whereNotNull('registered_at')
            ->whereBetween('registered_at', [
                $window->startsAt,
                $window->endsAt,
            ])
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        if ($registrations->isEmpty()) {
            return;
        }

        $messagesByRegistration = $this->confirmationMessages(
            registrations: $registrations,
        );
        $now = CarbonImmutable::now('UTC');

        foreach ($registrations as $registration) {
            $webinar = $registration->webinar;
            $series = $webinar?->webinarSeries;
            $meta = is_array($registration->meta)
                ? $registration->meta
                : [];
            $confirmationMessages = $messagesByRegistration
                ->get((string) $registration->getKey(), collect());
            $confirmation = $this->confirmationState($confirmationMessages);
            $attendanceFinalized = (bool) data_get(
                $webinar?->meta,
                'normalized.post_event.attendance_ready',
                false,
            );
            $registrationStatus = $this->normalizedString(
                $registration->status,
            ) ?? 'unknown';
            $providerRequired = $webinar !== null
                && filled($webinar->external_id)
                && filled($webinar->providerKey());
            $providerSyncStatus = $providerRequired
                ? ($this->normalizedString(
                    data_get($meta, 'provider_sync.status'),
                ) ?? 'pending')
                : 'not_required';
            $finalizationStatus = $this->normalizedString(
                data_get($meta, 'registration_finalization.status'),
            ) ?? 'unknown';
            $finalizationReason = $this->normalizedReasonCode(
                data_get($meta, 'registration_finalization.failure_reason')
                    ?? data_get($meta, 'registration_finalization.completion_reason'),
            );
            $publicSubmissionAttemptId = $this->publicSubmissionAttemptId(
                $meta['public_submission_attempt_id'] ?? null,
            );
            $acceptedTransactionalChannels = data_get(
                $meta,
                'accepted_channels.transactional',
                [],
            );
            $providerReadyForConfirmation = in_array(
                $providerSyncStatus,
                ['not_required', 'succeeded', 'already_succeeded'],
                true,
            );
            $confirmationEligible = $registration->source === 'webinar_subdomain'
                && $providerReadyForConfirmation
                && is_array($acceptedTransactionalChannels)
                && count(array_filter(
                    $acceptedTransactionalChannels,
                    fn (mixed $channel): bool =>
                        is_string($channel) && trim($channel) !== '',
                )) > 0;
            $joinInteraction = is_array($meta['join_interaction'] ?? null)
                ? $meta['join_interaction']
                : [];
            $joinConfirmed = ($joinInteraction['source'] ?? null)
                === 'public_signed_post'
                && filled($joinInteraction['first_confirmed_at'] ?? null);
            $occurrenceStarted = $webinar?->starts_at !== null
                && CarbonImmutable::instance($webinar->starts_at)
                    ->utc()
                    ->lessThanOrEqualTo($now);
            $attendanceStatus = $attendanceFinalized
                && in_array($registrationStatus, ['attended', 'missed'], true)
                    ? $registrationStatus
                    : null;

            yield new ReportingProjectionFact(
                key: self::FACT_KEY,
                version: self::FACT_VERSION,
                occurredAt: CarbonImmutable::instance(
                    $registration->registered_at,
                )->utc(),
                subjectType: $registration->getMorphClass(),
                subjectId: (string) $registration->getKey(),
                correlationId: $publicSubmissionAttemptId,
                dimensions: [
                    'series_id' => $series?->getKey() !== null
                        ? (string) $series->getKey()
                        : null,
                    'series_slug' => $this->normalizedString($series?->slug),
                    'occurrence_id' => $webinar?->getKey() !== null
                        ? (string) $webinar->getKey()
                        : null,
                    'occurrence_slug' => $this->normalizedString($webinar?->slug),
                    'source' => $this->normalizedString($registration->source),
                    'provider' => $this->normalizedString(
                        $webinar?->providerKey(),
                    ),
                ],
                values: [
                    'public_registration' =>
                        $registration->source === 'webinar_subdomain',
                    'registration_status' => $registrationStatus,
                    'finalization_status' => $finalizationStatus,
                    'finalization_reason' => $finalizationReason,
                    'provider_required' => $providerRequired,
                    'provider_sync_status' => $providerSyncStatus,
                    'confirmation_eligible' => $confirmationEligible,
                    'confirmation_planned' =>
                        $confirmation['planned_count'] > 0,
                    'confirmation_planned_count' =>
                        $confirmation['planned_count'],
                    'confirmation_sent_count' =>
                        $confirmation['sent_count'],
                    'confirmation_skipped_count' =>
                        $confirmation['skipped_count'],
                    'confirmation_failed_count' =>
                        $confirmation['failed_count'],
                    'confirmation_unresolved_count' =>
                        $confirmation['unresolved_count'],
                    'join_confirmed' => $joinConfirmed,
                    'occurrence_started' => $occurrenceStarted,
                    'attendance_finalized' => $attendanceFinalized,
                    'attendance_status' => $attendanceStatus,
                ],
            );

            if ($registration->source !== 'webinar_subdomain') {
                continue;
            }

            foreach ($registration->responses as $response) {
                $questionKey = $this->normalizedString($response->question_key);
                $answerKey = $this->normalizedString($response->answer_key);
                $definitionVersion = $this->normalizedString(
                    $response->definition_version,
                );

                if ($questionKey === null
                    || $answerKey === null
                    || $definitionVersion === null
                ) {
                    continue;
                }

                yield new ReportingProjectionFact(
                    key: self::QUESTION_FACT_KEY,
                    version: self::FACT_VERSION,
                    occurredAt: CarbonImmutable::instance(
                        $registration->registered_at,
                    )->utc(),
                    subjectType: $response->getMorphClass(),
                    subjectId: (string) $response->getKey(),
                    dimensions: [
                        'series_id' => $series?->getKey() !== null
                            ? (string) $series->getKey()
                            : null,
                        'series_slug' => $this->normalizedString($series?->slug),
                        'occurrence_id' => $webinar?->getKey() !== null
                            ? (string) $webinar->getKey()
                            : null,
                        'occurrence_slug' => $this->normalizedString($webinar?->slug),
                    ],
                    values: [
                        'question_key' => $questionKey,
                        'answer_key' => $answerKey,
                        'definition_version' => $definitionVersion,
                    ],
                );
            }
        }
    }

    /**
     * @param Collection<int, WebinarRegistration> $registrations
     * @return Collection<string, Collection<int, ScheduledMessage>>
     */
    private function confirmationMessages(Collection $registrations): Collection
    {
        $registrationIds = $registrations
            ->modelKeys();

        if ($registrationIds === []) {
            return collect();
        }

        $morphClass = $registrations->first()->getMorphClass();

        return ScheduledMessage::query()
            ->with([
                'components',
                'terminalOutboxEvent.deliveryAttempt',
            ])
            ->where('context_type', $morphClass)
            ->whereIn('context_id', $registrationIds)
            ->where('purpose', 'transactional')
            ->where('scope', 'webinar')
            ->where(function ($query): void {
                $query
                    ->where('message_type', 'confirmation')
                    ->orWhereHas(
                        'components',
                        fn ($components) => $components->where(
                            'intent_key',
                            self::CONFIRMATION_INTENT,
                        ),
                    );
            })
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ScheduledMessage $message): string =>
                (string) $message->context_id
            );
    }

    /**
     * @param Collection<int, ScheduledMessage> $messages
     * @return array{
     *     planned_count: int,
     *     sent_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     unresolved_count: int
     * }
     */
    private function confirmationState(Collection $messages): array
    {
        $state = [
            'planned_count' => $messages->count(),
            'sent_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'unresolved_count' => 0,
        ];

        foreach ($messages as $message) {
            if (! in_array($message->status, [
                ScheduledMessage::STATUS_SENT,
                ScheduledMessage::STATUS_SKIPPED,
                ScheduledMessage::STATUS_FAILED,
            ], true)) {
                $state['unresolved_count']++;

                continue;
            }

            try {
                $terminal = ScheduledMessageTerminalResult::fromScheduledMessage(
                    $message,
                );
            } catch (Throwable) {
                $state['unresolved_count']++;

                continue;
            }

            match ($terminal->status) {
                ScheduledMessage::STATUS_SENT => $state['sent_count']++,
                ScheduledMessage::STATUS_SKIPPED => $state['skipped_count']++,
                ScheduledMessage::STATUS_FAILED => $state['failed_count']++,
                default => $state['unresolved_count']++,
            };
        }

        return $state;
    }

    private function publicSubmissionAttemptId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' && Str::isUuid($value)
            ? $value
            : null;
    }

    private function normalizedReasonCode(mixed $value): ?string
    {
        $value = $this->normalizedString($value);

        if ($value === null
            || strlen($value) > 96
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) !== 1
        ) {
            return null;
        }

        return $value;
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? mb_substr($value, 0, 255)
            : null;
    }
}