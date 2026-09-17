<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Jobs\ProcessWebinarScheduleChangeJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarProviderManager;
use App\Modules\Webinars\Services\WebinarProviderSchedulePolicy;
use App\Modules\Webinars\Services\WebinarTimeChangeTemplates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncWebinarSeriesFromProviderAction
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCachesAction,
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
        private readonly WebinarProviderManager $webinarProviderManager,
        private readonly WebinarProviderSchedulePolicy $providerSchedulePolicy,
        private readonly ReconcileWebinarMessageScheduleAction $reconcileMessageSchedule,
        private readonly WebinarTimeChangeTemplates $timeChangeTemplates,
    ) {}

    public function execute(WebinarSeries $series): array
    {
        $hadUpcomingWebinarBeforeSync = filled(
            $this->getNextUpcomingWebinarAction->getForSeries($series)
        );

        $webinarProvider = $this->webinarProviderManager->forSeries($series);
        $provider = $series->providerKey();
        $providerEventType = $series->providerEventTypeKey();

        $snapshot = $this->providerSnapshot(
            $webinarProvider->listWebinarsByTitle($series->title),
        );
        $providerWebinars = collect($snapshot->webinars)->values();

        $providerReturnedExternalIds = $providerWebinars
            ->map(fn (ProviderWebinarData $webinar) => $webinar->externalId)
            ->filter()
            ->values()
            ->all();

        $scheduleEligibleProviderWebinars = $providerWebinars
            ->filter(fn (ProviderWebinarData $webinar): bool =>
                $this->providerSchedulePolicy->allowsProviderOccurrence(
                    $series,
                    $webinar,
                )
            )
            ->values();
        $ignoredScheduleOutliers = $providerWebinars->count()
            - $scheduleEligibleProviderWebinars->count();

        $suppressedExternalIds = $this->suppressedExternalIds(
            series: $series,
            provider: $provider,
            providerEventType: $providerEventType,
            fetchedExternalIds: $providerReturnedExternalIds,
        );

        $fetchedWebinars = $scheduleEligibleProviderWebinars
            ->reject(fn (ProviderWebinarData $webinar): bool => in_array(
                $webinar->externalId,
                $suppressedExternalIds,
                true,
            ))
            ->values();

        $created = 0;
        $updated = 0;
        $createdWebinarIds = [];
        $missing = [];
        $messageSchedule = [
            'enrollments' => 0,
            'messages' => 0,
            'review_required' => 0,
            'review_enrollment_ids' => [],
            'review_message_ids' => [],
        ];

        $fetchedWebinars->each(function (ProviderWebinarData $fetchedWebinar) use (
            $series,
            $provider,
            $providerEventType,
            &$created,
            &$updated,
            &$createdWebinarIds,
            &$messageSchedule,
        ): void {
            $outcome = DB::transaction(function () use (
                $fetchedWebinar,
                $series,
                $provider,
                $providerEventType,
            ): array {
                $webinar = Webinar::query()->lockForUpdate()->firstOrNew([
                    'platform' => $provider,
                    'provider_event_type' => $providerEventType,
                    'external_id' => $fetchedWebinar->externalId,
                    'webinar_series_id' => $series->id,
                ]);

                $attributes = [
                    'platform' => $provider,
                    'provider_event_type' => $providerEventType,
                    'title' => $fetchedWebinar->title,
                    'join_url' => $fetchedWebinar->joinUrl,
                    'registration_url' => $fetchedWebinar->registrationUrl ?? $webinar->registration_url,
                    'starts_at' => $fetchedWebinar->startsAt,
                    'ends_at' => $fetchedWebinar->endsAt,
                    'timezone' => $fetchedWebinar->timezone,
                    'description' => $fetchedWebinar->description,
                    'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Active->value,
                    'provider_missing_at' => null,
                    'provider_archived_at' => null,
                    'meta' => $this->mergeProviderMeta(
                        webinar: $webinar,
                        provider: $provider,
                        providerMeta: $fetchedWebinar->meta,
                    ),
                ];

                if (! $webinar->exists) {
                    $attributes['slug'] = $this->makeSlug(
                        title: $fetchedWebinar->title,
                        provider: $provider,
                        providerEventType: $providerEventType,
                        externalId: $fetchedWebinar->externalId,
                    );

                    $webinar->provider_settings = null;
                }

                $previousStart = $webinar->exists ? $webinar->starts_at?->copy() : null;
                $previousTimezone = $webinar->exists ? $webinar->timezone : null;
                $webinar->fill($attributes);
                $webinar->save();

                $impact = null;

                if ($previousStart !== null && $webinar->starts_at !== null
                    && ! $previousStart->equalTo($webinar->starts_at)
                ) {
                    $impact = $this->reconcileMessageSchedule->handle(
                        $webinar,
                        $previousStart,
                        $webinar->starts_at,
                    );
                }

                if ($previousStart !== null && $webinar->starts_at !== null
                    && (! $previousStart->equalTo($webinar->starts_at)
                        || $previousTimezone !== $webinar->timezone)
                ) {
                    // Keep the old display zone: provider resync has already
                    // replaced the occurrence's timezone at this point.
                    $older = WebinarScheduleChange::query()
                        ->where('webinar_id', $webinar->getKey())
                        ->whereIn('status', [
                            WebinarScheduleChange::STATUS_PENDING,
                            WebinarScheduleChange::STATUS_DISPATCHING,
                        ])->pluck('id');

                    if ($older->isNotEmpty()) {
                        WebinarScheduleChange::query()->whereIn('id', $older)
                            ->update(['status' => WebinarScheduleChange::STATUS_SUPERSEDED]);

                        ScheduledMessage::query()
                            ->where('behavior_owner_type', (new WebinarScheduleChange)->getMorphClass())
                            ->whereIn('behavior_owner_id', $older)
                            ->where('message_type', 'webinar_schedule_change')
                            ->where('status', ScheduledMessage::STATUS_PENDING)
                            ->orderBy('id')->chunkById(100, function ($messages): void {
                                foreach ($messages as $message) {
                                    $message->forceFill([
                                        'status' => ScheduledMessage::STATUS_CANCELLED,
                                        'operational_state' => ScheduledMessage::OPERATIONAL_CANCELLED,
                                    ])->save();
                                    WebinarScheduleChange::query()
                                        ->whereKey($message->behavior_owner_id)
                                        ->increment('messages_cancelled');
                                }
                            });
                    }

                    $change = WebinarScheduleChange::query()->create([
                        'webinar_id' => $webinar->getKey(),
                        'previous_starts_at' => $previousStart,
                        'current_starts_at' => $webinar->starts_at,
                        'previous_timezone' => $previousTimezone ?? $webinar->timezone,
                        'current_timezone' => $webinar->timezone,
                        'status' => $webinar->starts_at->isFuture()
                            ? WebinarScheduleChange::STATUS_PENDING
                            : WebinarScheduleChange::STATUS_SUPERSEDED,
                    ]);

                    if ($change->status === WebinarScheduleChange::STATUS_PENDING
                        && $series->status === 'active'
                        && function_exists('module_enabled')
                        && module_enabled('messaging')
                    ) {
                        $currentSeries = $series->fresh();

                        if ($currentSeries && $this->timeChangeTemplates->autoSend($currentSeries)) {
                            $channels = $this->timeChangeTemplates->configuredChannels($currentSeries);
                            $versions = $this->timeChangeTemplates->versionsFor($currentSeries, $channels);

                            if ($channels !== []
                                && ! array_diff($channels, $this->timeChangeTemplates->availableChannels())
                                && count($versions) === count($channels)
                            ) {
                                $change->update([
                                    'status' => WebinarScheduleChange::STATUS_DISPATCHING,
                                    'notification_mode' => 'automatic',
                                    'channels' => $channels,
                                    'template_version_ids' => $versions,
                                    'queued_at' => now(),
                                ]);
                                ProcessWebinarScheduleChangeJob::dispatch((int) $change->getKey())
                                    ->afterCommit();
                            } else {
                                Log::warning('Webinar time-change auto-send needs operator review.', [
                                    'webinar_id' => $webinar->getKey(),
                                    'change_id' => $change->getKey(),
                                    'reason' => 'Selected channel or published template unavailable.',
                                ]);
                            }
                        }
                    }
                }

                return [
                    'created' => $webinar->wasRecentlyCreated,
                    'webinar_id' => (int) $webinar->getKey(),
                    'impact' => $impact,
                ];
            }, 3);

            if ($outcome['created']) {
                $created++;
                $createdWebinarIds[] = $outcome['webinar_id'];
            } else {
                $updated++;
            }

            if (is_array($outcome['impact'])) {
                foreach ($outcome['impact'] as $key => $count) {
                    if (is_array($count)) {
                        $messageSchedule[$key] = array_merge($messageSchedule[$key], $count);
                    } else {
                        $messageSchedule[$key] += $count;
                    }
                }
            }
        });

        if ($messageSchedule['review_required'] > 0) {
            Log::warning('Webinar reminder schedules require review after provider resync.', [
                'series_id' => $series->getKey(),
                'review_required' => $messageSchedule['review_required'],
                'review_enrollment_ids' => $messageSchedule['review_enrollment_ids'],
                'review_message_ids' => $messageSchedule['review_message_ids'],
            ]);
        }

        if ($snapshot->authoritative) {
            foreach ($this->missingWebinars(
                series: $series,
                provider: $provider,
                providerEventType: $providerEventType,
                fetchedExternalIds: $providerReturnedExternalIds,
            ) as $missingWebinar) {
                $missingWebinar->forceFill([
                    'provider_lifecycle_status' => WebinarProviderLifecycleStatus::Missing->value,
                    'provider_missing_at' => now(),
                    'provider_archived_at' => null,
                ])->save();

                $missing[] = [
                    'webinar_id' => $missingWebinar->getKey(),
                    'external_id' => $missingWebinar->external_id,
                    'platform' => $missingWebinar->providerKey(),
                    'provider_event_type' => $missingWebinar->providerEventTypeKey(),
                    'title' => $missingWebinar->title,
                    'has_registrations' => $missingWebinar->registrations()->exists(),
                    'provider_missing_at' => $missingWebinar->provider_missing_at?->toISOString(),
                ];
            }
        }

        $this->getNextUpcomingWebinarAction->forgetForSeries($series);
        $this->getNextUpcomingWebinarAction->forgetGlobal();

        $this->flushWebinarCachesAction->handle(seriesSlug: $series->slug);

        $hasUpcomingWebinarAfterSync = filled(
            $this->getNextUpcomingWebinarAction->getForSeries($series)
        );

        if (
            $hasUpcomingWebinarAfterSync
            && (
                ! $hadUpcomingWebinarBeforeSync
                || $this->hasUnnotifiedWaitlistSignups($series)
            )
        ) {
            NotifyWebinarWaitlistJob::dispatch($series->id);
        }

        if ($this->hasActiveRecurringWaitlistSubscriptions($series)) {
            foreach ($createdWebinarIds as $webinarId) {
                NotifyWebinarWaitlistJob::dispatch(
                    (int) $series->getKey(),
                    $webinarId,
                    WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
                );
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => 0,
            'removed_from_provider' => count($missing),
            'suppressed' => count($suppressedExternalIds),
            'ignored_schedule_outliers' => $ignoredScheduleOutliers,
            'conflicts' => [],
            'missing' => $missing,
            'message_schedule' => $messageSchedule,
            'reconciliation' => [
                'authoritative' => $snapshot->authoritative,
                'reason' => $snapshot->reason,
                'provider' => $provider,
                'provider_event_type' => $providerEventType,
                'missing_candidates' => count($missing),
                'ignored_schedule_outliers' => $ignoredScheduleOutliers,
            ],
        ];
    }

    private function providerSnapshot(iterable $providerResult): ProviderWebinarSnapshot
    {
        if ($providerResult instanceof ProviderWebinarSnapshot) {
            return $providerResult;
        }

        return ProviderWebinarSnapshot::nonAuthoritative(
            webinars: $providerResult,
            reason: 'provider_snapshot_authority_unspecified',
        );
    }

    /**
     * @param array<string, mixed> $providerMeta
     * @return array<string, mixed>
     */
    private function mergeProviderMeta(
        Webinar $webinar,
        string $provider,
        array $providerMeta,
    ): array {
        $meta = is_array($webinar->meta) ? $webinar->meta : [];

        if ($provider === 'zoom') {
            unset($meta['zoom_uuid']);
        }

        $meta['provider'] = [
            'key' => $provider,
            'data' => $providerMeta,
        ];

        return $meta;
    }

    private function hasUnnotifiedWaitlistSignups(WebinarSeries $series): bool
    {
        return WebinarWaitlistSignup::query()
            ->where('webinar_series_id', $series->getKey())
            ->eligibleForNotification(WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE)
            ->exists();
    }

    private function hasActiveRecurringWaitlistSubscriptions(
        WebinarSeries $series,
    ): bool {
        return WebinarWaitlistSignup::query()
            ->where('webinar_series_id', $series->getKey())
            ->eligibleForNotification(WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING)
            ->exists();
    }


    /**
     * @param array<int, string> $fetchedExternalIds
     * @return array<int, string>
     */
    private function suppressedExternalIds(
        WebinarSeries $series,
        string $provider,
        string $providerEventType,
        array $fetchedExternalIds,
    ): array {
        if ($fetchedExternalIds === []) {
            return [];
        }

        return WebinarOccurrenceSuppression::query()
            ->where('webinar_series_id', $series->getKey())
            ->where('platform', $provider)
            ->where('provider_event_type', $providerEventType)
            ->whereIn('external_id', $fetchedExternalIds)
            ->pluck('external_id')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    protected function missingWebinars(
        WebinarSeries $series,
        string $provider,
        string $providerEventType,
        array $fetchedExternalIds,
    ): Collection {
        return $series->webinars()
            ->where('platform', $provider)
            ->where('provider_event_type', $providerEventType)
            ->providerActive()
            ->where(function ($query): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->when(
                filled($fetchedExternalIds),
                fn ($query) => $query->whereNotIn('external_id', $fetchedExternalIds),
            )
            ->get();
    }

    protected function makeSlug(
        string $title,
        string $provider,
        string $providerEventType,
        string $externalId,
    ): string {
        return Str::slug(implode('-', [
            $title,
            $provider,
            $providerEventType,
            $externalId,
        ]));
    }
}