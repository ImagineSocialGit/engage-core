<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\RemoveWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\RemoveWebinarSeriesAction;
use App\Modules\Webinars\Actions\RestoreRemovedWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\RestoreWebinarSeriesAction;
use App\Modules\Webinars\Actions\ReplaceWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Enums\WebinarProviderLifecycleStatus;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Requests\ReplaceWebinarOccurrenceRequest;
use App\Modules\Webinars\Requests\StoreWebinarSeriesRequest;
use App\Modules\Webinars\Requests\SyncWebinarSeriesRequest;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesProviderEventTypeRequest;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesScheduleProfileRequest;
use App\Modules\Webinars\Services\WebinarMessageChainPresentationService;
use App\Modules\Webinars\Services\WebinarScheduleProfileResolver;
use App\Support\Reporting\PaidAdTrackingLinkGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LogicException;

class WebinarController extends Controller
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCachesAction,
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
    ) {}

    public function index(
        Request $request,
        WebinarMessageChainPresentationService $messageChainPresentation,
        WebinarScheduleProfileResolver $scheduleProfileResolver,
        PaidAdTrackingLinkGenerator $paidAdTrackingLinkGenerator,
    ): View {

        $showArchivedTypes = $request->boolean('archived_types');

        $series = WebinarSeries::query()
            ->where('status', $showArchivedTypes ? 'inactive' : 'active')
            ->with([
                'webinarScheduleProfile',
                'messageChainBindings' => fn ($query) => $query
                    ->active()
                    ->with('messageChain.currentVersion'),
                'webinars' => fn ($query) => $query
                    ->withCount('registrations')
                    ->whereNull('hidden_at')
                    ->where('provider_lifecycle_status', WebinarProviderLifecycleStatus::Active->value)
                    ->where('ends_at', '>', now())
                    ->orderBy('starts_at')
                    ->orderBy('id'),
            ])
            ->withCount([
                'webinars as upcoming_sessions_count' => fn ($query) => $query
                    ->whereNull('hidden_at')
                    ->where('provider_lifecycle_status', WebinarProviderLifecycleStatus::Active->value)
                    ->where('ends_at', '>', now()),
                'webinars as past_sessions_count' => fn ($query) => $query
                    ->whereNull('hidden_at')
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<=', now()),
                'webinars as removed_sessions_count' => fn ($query) => $query
                    ->whereNotNull('hidden_at'),
                'occurrenceSuppressions as suppressed_sessions_count',
            ])
            ->orderBy('title')
            ->get();

        $archivedTypeCount = WebinarSeries::query()
            ->where('status', 'inactive')
            ->count();

        $scheduleProfiles = WebinarScheduleProfile::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $upcomingWebinars = Webinar::query()
            ->with([
                'webinarScheduleProfile',
                'webinarSeries.webinarScheduleProfile',
            ])
            ->withCount('registrations')
            ->where('ends_at', '>', now())
            ->whereHas('webinarSeries', fn ($query) => $query->where('status', 'active'))
            ->providerActive()
            ->visible()
            ->matchingCurrentSeriesProvider()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(2)
            ->get();

        $upcomingMessageReviews = collect();

        if (function_exists('module_enabled') && module_enabled('messaging')) {
            $upcomingMessageReviews = $upcomingWebinars
                ->mapWithKeys(fn (Webinar $webinar): array => [
                    (int) $webinar->getKey() => $messageChainPresentation
                        ->forWebinar($webinar),
                ]);
        }

        $upcomingMessagePurposeReviews = $upcomingMessageReviews
            ->map(fn (array $presentation): array =>
                (int) ($presentation['message_count'] ?? 0) > 0
                    ? ['transactional' => $presentation]
                    : []
            );

        $upcomingMessageProfiles = $upcomingWebinars
            ->mapWithKeys(function (Webinar $webinar) use ($scheduleProfileResolver): array {
                $effectiveProfile = $scheduleProfileResolver
                    ->resolveForWebinar($webinar);
                $inheritedProfile = $scheduleProfileResolver
                    ->resolveForSeries($webinar->webinarSeries);
                $source = 'default';

                if (
                    $effectiveProfile !== null
                    && $webinar->webinarScheduleProfile?->is($effectiveProfile)
                ) {
                    $source = 'occurrence';
                } elseif (
                    $effectiveProfile !== null
                    && $webinar->webinarSeries?->webinarScheduleProfile?->is(
                        $effectiveProfile,
                    )
                ) {
                    $source = 'series';
                }

                return [
                    (int) $webinar->getKey() => [
                        'effective_profile_id' => $effectiveProfile?->getKey(),
                        'effective_profile_name' => $effectiveProfile?->name,
                        'inherited_profile_id' => $inheritedProfile?->getKey(),
                        'inherited_profile_name' => $inheritedProfile?->name,
                        'source' => $source,
                    ],
                ];
            });

        $pendingPostEventReviews = collect();

        if (config('webinars.post_event.review.required', false)) {
            $pendingPostEventReviews = Webinar::query()
                ->with('webinarSeries')
                ->withCount([
                    'registrations as attended_registrations_count' => fn ($query) => $query->whereNotNull('attended_at'),
                    'registrations as missed_registrations_count' => fn ($query) => $query->where('status', 'missed'),
                ])
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now())
                ->where('meta->normalized->post_event->review->status', 'pending')
                ->orderByDesc('ends_at')
                ->orderByDesc('id')
                ->limit(4)
                ->get();
        }

        $registrationAttentionCount = WebinarRegistration::query()
            ->where(function ($query): void {
                $query
                    ->whereIn('meta->registration_finalization->status', [
                        'failed',
                        'reconciliation_required',
                    ])
                    ->orWhere(
                        'meta->provider_sync->status',
                        'reconciliation_required',
                    );
            })
            ->count();

        $providerMissingOccurrences = Webinar::query()
            ->with('webinarSeries')
            ->withCount('registrations')
            ->whereHas('webinarSeries', fn ($query) => $query->where('status', 'active'))
            ->providerMissing()
            ->visible()
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $showArchived = $request->boolean('archived');
        $showAttention = $request->boolean('attention');
        $allOccurrences = Webinar::query()
            ->with([
                'webinarSeries',
                'replacementOf',
                'replacement',
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $query = Webinar::query()
            ->with([
                'webinarSeries',
                'replacementOf',
                'replacement',
                'registrations' => fn ($query) => $query
                    ->with('contact')
                    ->latest('registered_at')
                    ->latest('id'),
            ]);

        if ($showAttention) {
            $query->where(function ($query): void {
                $query
                    ->where(function ($missingQuery): void {
                        $missingQuery
                            ->whereHas('webinarSeries', fn ($seriesQuery) => $seriesQuery->where('status', 'active'))
                            ->where(
                                'provider_lifecycle_status',
                                WebinarProviderLifecycleStatus::Missing->value,
                            )
                            ->whereNull('hidden_at');
                    })
                    ->orWhereHas('registrations', fn ($query) => $query
                        ->where(function ($query): void {
                            $query
                                ->whereIn('meta->registration_finalization->status', [
                                    'failed',
                                    'reconciliation_required',
                                ])
                                ->orWhere(
                                    'meta->provider_sync->status',
                                    'reconciliation_required',
                                );
                        }));
            });
        } elseif (! $showArchived) {
            $query
                ->where('ends_at', '>', now())
                ->providerActive()
                ->visible()
                ->matchingCurrentSeriesProvider();
        }

        $webinars = $query
            ->when(
                $showAttention,
                fn ($query) => $query->orderByDesc('starts_at'),
                fn ($query) => $query->orderBy('starts_at'),
            )
            ->limit(50)
            ->get();

        $archivedRecoveryRows = $showArchived
            ? $webinars
                ->map(function (Webinar $webinar): array {
                    $providerCancellationFailures = $webinar->registrations
                        ->filter(
                            fn (WebinarRegistration $registration): bool =>
                                data_get(
                                    $registration->meta,
                                    'provider_cancellation.status',
                                ) === 'failed',
                        )
                        ->values();

                    $followUpFailures = $webinar->registrations
                        ->filter(
                            fn (WebinarRegistration $registration): bool =>
                                data_get(
                                    $registration->meta,
                                    'post_event_follow_up.status',
                                ) === 'failed',
                        )
                        ->values();

                    return [
                        'webinar' => $webinar,
                        'provider_cancellation_failures' => $providerCancellationFailures,
                        'provider_cancellation_failure_count' => $providerCancellationFailures->count(),
                        'follow_up_failures' => $followUpFailures,
                        'follow_up_failure_count' => $followUpFailures->count(),
                    ];
                })
                ->values()
            : collect();

        $webinarLinkOptions = $upcomingWebinars
            ->concat($webinars)
            ->unique(fn (Webinar $webinar): int => (int) $webinar->getKey())
            ->filter(fn (Webinar $webinar): bool => filled($webinar->webinarSeries?->slug))
            ->mapWithKeys(function (Webinar $webinar): array {
                $startsAtLabel = $webinar->starts_at?->copy()
                    ->setTimezone($webinar->timezone)
                    ->format('M j, Y · g:i A T');

                return [
                    (string) $webinar->getKey() => [
                        'webinar_id' => (int) $webinar->getKey(),
                        'webinar_title' => $webinar->title,
                        'series_title' => $webinar->webinarSeries?->title,
                        'starts_at_label' => $startsAtLabel,
                        'option_label' => trim(implode(' — ', array_filter([
                            $webinar->title,
                            $startsAtLabel,
                        ]))),
                        'destination_url' => route('webinar.show', [
                            'seriesSlug' => $webinar->webinarSeries->slug,
                        ]),
                    ],
                ];
            });

        $paidAdTrackingPlatforms = function_exists('module_enabled')
            && module_enabled('reporting')
                ? $paidAdTrackingLinkGenerator->platforms()
                : [];

        return view('crm.webinars.index', [
            'title' => 'Webinars',
            'heading' => 'Webinars',
            'webinars' => $webinars,
            'series' => $series,
            'showArchivedTypes' => $showArchivedTypes,
            'archivedTypeCount' => $archivedTypeCount,
            'scheduleProfiles' => $scheduleProfiles,
            'upcomingWebinars' => $upcomingWebinars,
            'upcomingMessageReviews' => $upcomingMessageReviews,
            'upcomingMessagePurposeReviews' => $upcomingMessagePurposeReviews,
            'upcomingMessageProfiles' => $upcomingMessageProfiles,
            'pendingPostEventReviews' => $pendingPostEventReviews,
            'registrationAttentionCount' => $registrationAttentionCount,
            'providerMissingOccurrences' => $providerMissingOccurrences,
            'providerMissingCount' => $providerMissingOccurrences->count(),
            'attentionCount' => $pendingPostEventReviews->count()
                + $registrationAttentionCount
                + $providerMissingOccurrences->count(),
            'showArchived' => $showArchived,
            'archivedRecoveryRows' => $archivedRecoveryRows,
            'showAttention' => $showAttention,
            'providerEventTypeOptions' => $this->providerEventTypeOptions(),
            'replacementCandidatesBySourceId' => $this->replacementCandidatesBySourceId(
                $allOccurrences,
            ),
            'webinarDevEnabled' => $this->devTestingAllowed(),
            'webinarSmokeEnabled' => $this->devTestingAllowed(),
            'webinarLinkOptions' => $webinarLinkOptions,
            'paidAdTrackingPlatforms' => $paidAdTrackingPlatforms,
        ]);
    }


    public function showSeries(
        WebinarSeries $series,
        WebinarMessageChainPresentationService $messageChainPresentation,
        WebinarScheduleProfileResolver $scheduleProfileResolver,
        PaidAdTrackingLinkGenerator $paidAdTrackingLinkGenerator,
        RemoveWebinarSeriesAction $removeWebinarSeries,
    ): View {
        $series->load([
            'webinarScheduleProfile',
            'messageChainBindings' => fn ($query) => $query
                ->active()
                ->with('messageChain.currentVersion'),
            'occurrenceSuppressions' => fn ($query) => $query
                ->latest('suppressed_at')
                ->latest('id'),
        ]);

        $occurrences = $series->webinars()
            ->withCount('registrations')
            ->with([
                'webinarScheduleProfile',
                'replacementOf',
                'replacement',
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $currentEventType = $series->providerEventTypeKey();

        $currentTypeOccurrences = $occurrences
            ->filter(fn (Webinar $webinar): bool =>
                $webinar->providerEventTypeKey() === $currentEventType
            );

        $upcoming = $currentTypeOccurrences
            ->filter(fn (Webinar $webinar): bool =>
                ! $webinar->isHidden()
                && $webinar->isProviderActive()
                && $webinar->ends_at?->isFuture()
            )
            ->values();

        $history = $occurrences
            ->filter(fn (Webinar $webinar): bool =>
                ! $webinar->isHidden()
                && ($webinar->ends_at?->isPast() ?? false)
            )
            ->sortByDesc(fn (Webinar $webinar): string =>
                $webinar->ends_at?->format('Y-m-d H:i:s') ?? ''
            )
            ->values();

        $providerMissing = (string) $series->status === 'active'
            ? $occurrences
                ->filter(fn (Webinar $webinar): bool =>
                    ! $webinar->isHidden()
                    && $webinar->provider_lifecycle_status
                        === WebinarProviderLifecycleStatus::Missing->value
                )
                ->values()
            : collect();

        $removed = $occurrences
            ->filter(fn (Webinar $webinar): bool => $webinar->isHidden())
            ->sortByDesc(fn (Webinar $webinar): string =>
                $webinar->hidden_at?->format('Y-m-d H:i:s') ?? ''
            )
            ->values();

        $messageReview = function_exists('module_enabled')
            && module_enabled('messaging')
                ? $messageChainPresentation->forSeries($series)
                : [
                    'message_count' => 0,
                    'channels' => [],
                    'chains' => [],
                    'has_series_owned_messages' => false,
                ];

        $messageProfile = $scheduleProfileResolver->resolveForSeries($series);

        $paidAdTrackingPlatforms = function_exists('module_enabled')
            && module_enabled('reporting')
                ? $paidAdTrackingLinkGenerator->platforms()
                : [];

        return view('crm.webinars.series-show', [
            'title' => $series->title,
            'heading' => $series->title,
            'series' => $series,
            'upcomingWebinars' => $upcoming,
            'historyWebinars' => $history,
            'providerMissingOccurrences' => $providerMissing,
            'removedWebinars' => $removed,
            'suppressedOccurrences' => $series->occurrenceSuppressions,
            'messageReview' => $messageReview,
            'messageProfile' => $messageProfile,
            'registrationUrl' => route('webinar.show', [
                'seriesSlug' => $series->slug,
            ]),
            'paidAdTrackingPlatforms' => $paidAdTrackingPlatforms,
            'providerEventTypeLabel' => $this->providerEventTypeLabel(
                $series->providerEventTypeKey(),
            ),
            'seriesRemovalPlan' => $removeWebinarSeries->plan($series),
        ]);
    }

    public function showWebinar(
        Webinar $webinar,
        WebinarMessageChainPresentationService $messageChainPresentation,
        WebinarScheduleProfileResolver $scheduleProfileResolver,
    ): View {
        $webinar->load([
            'webinarSeries.webinarScheduleProfile',
            'webinarScheduleProfile',
            'replacementOf',
            'replacement',
        ]);

        $registrations = $webinar->registrations()
            ->with([
                'contact',
                'responses',
            ])
            ->latest('registered_at')
            ->latest('id')
            ->paginate(50);

        $registrationCounts = [
            'total' => $webinar->registrations()->count(),
            'attended' => $webinar->registrations()
                ->where(function ($query): void {
                    $query
                        ->where('status', 'attended')
                        ->orWhereNotNull('attended_at');
                })
                ->count(),
            'missed' => $webinar->registrations()
                ->where('status', 'missed')
                ->count(),
            'cancelled' => $webinar->registrations()
                ->whereNotNull('cancelled_at')
                ->count(),
        ];

        $messageReview = function_exists('module_enabled')
            && module_enabled('messaging')
                ? $messageChainPresentation->forWebinar($webinar)
                : [
                    'message_count' => 0,
                    'channels' => [],
                    'chains' => [],
                    'has_series_owned_messages' => false,
                ];

        $effectiveProfile = $scheduleProfileResolver->resolveForWebinar($webinar);
        $inheritedProfile = $scheduleProfileResolver->resolveForSeries(
            $webinar->webinarSeries,
        );
        $profileSource = 'default';

        if (
            $effectiveProfile !== null
            && $webinar->webinarScheduleProfile?->is($effectiveProfile)
        ) {
            $profileSource = 'occurrence';
        } elseif (
            $effectiveProfile !== null
            && $webinar->webinarSeries?->webinarScheduleProfile?->is(
                $effectiveProfile,
            )
        ) {
            $profileSource = 'series';
        }

        $replacementCandidates = collect();

        if ($webinar->webinar_series_id !== null) {
            $allOccurrences = Webinar::query()
                ->where('webinar_series_id', $webinar->webinar_series_id)
                ->with([
                    'webinarSeries',
                    'replacementOf',
                    'replacement',
                ])
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();

            $replacementCandidates = $this->replacementCandidatesBySourceId(
                $allOccurrences,
            )[(int) $webinar->getKey()] ?? collect();
        }

        return view('crm.webinars.show', [
            'title' => $webinar->title,
            'heading' => $webinar->title,
            'webinar' => $webinar,
            'series' => $webinar->webinarSeries,
            'registrations' => $registrations,
            'registrationCounts' => $registrationCounts,
            'messageReview' => $messageReview,
            'messageProfile' => [
                'effective_profile_id' => $effectiveProfile?->getKey(),
                'effective_profile_name' => $effectiveProfile?->name,
                'inherited_profile_id' => $inheritedProfile?->getKey(),
                'inherited_profile_name' => $inheritedProfile?->name,
                'source' => $profileSource,
            ],
            'scheduleProfiles' => WebinarScheduleProfile::query()
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'replacementCandidates' => $replacementCandidates,
            'webinarDevEnabled' => $this->devTestingAllowed(),
        ]);
    }

    public function restoreOccurrence(
        Webinar $webinar,
        RestoreRemovedWebinarOccurrenceAction $restoreRemovedWebinarOccurrence,
    ): RedirectResponse {
        $series = $restoreRemovedWebinarOccurrence->restoreHidden($webinar);

        return redirect()
            ->route('crm.webinar-series.show', $series)
            ->with('success', 'The session is visible again.');
    }

    public function restoreSuppressedOccurrence(
        WebinarOccurrenceSuppression $suppression,
        RestoreRemovedWebinarOccurrenceAction $restoreRemovedWebinarOccurrence,
    ): RedirectResponse {
        $series = $restoreRemovedWebinarOccurrence->restoreSuppression(
            $suppression,
        );

        return redirect()
            ->route('crm.webinar-series.show', $series)
            ->with(
                'success',
                'The session can be imported again the next time this webinar type is synced from Zoom.',
            );
    }

    public function storeSeries(StoreWebinarSeriesRequest $request): RedirectResponse
    {
        WebinarSeries::query()->create($request->validated());

        $this->flushWebinarCachesAction->handle();

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar series created.');
    }

    public function syncSeries(
        SyncWebinarSeriesRequest $request,
        SyncWebinarSeriesFromProviderAction $syncWebinarSeriesFromProviderAction,
    ): RedirectResponse {
        $series = WebinarSeries::query()->findOrFail($request->validated('webinar_series_id'));

        if ((string) $series->status !== 'active') {
            return redirect()
                ->route('crm.webinar-series.show', $series)
                ->with('error', 'Restore this webinar type before syncing it from Zoom.');
        }

        $eventTypeLabel = $this->providerEventTypeLabel($series->providerEventTypeKey());

        try {
            $result = $syncWebinarSeriesFromProviderAction->execute($series);
        } catch (RequestException $e) {
            report($e);

            $message = $e->response?->json('message')
                ?? $e->response?->body()
                ?? 'Zoom sync failed.';

            return redirect()
                ->route('crm.webinar-series.index')
                ->with('zoom_sync_error', $message);
        } catch (ConnectionException $e) {
            report($e);

            return redirect()
                ->route('crm.webinar-series.index')
                ->with('zoom_sync_error', 'Unable to connect to Zoom.');
        }

        $suppressedCount = (int) ($result['suppressed'] ?? 0);
        $syncSummary = "Sync complete: {$result['created']} created, {$result['updated']} updated, "
            .count($result['missing']).' removed from the active Zoom schedule.';

        if ($suppressedCount > 0) {
            $syncSummary .= ' '.number_format($suppressedCount).' intentionally removed '
                .Str::plural('event', $suppressedCount).' kept out.';
        }

        $redirect = redirect()
            ->route('crm.webinar-series.index')
            ->with('success', $syncSummary)
            ->with('sync_conflicts', $result['conflicts'])
            ->with('sync_missing', $result['missing']);

        if (! data_get($result, 'reconciliation.authoritative', false)) {
            $redirect->with(
                'error',
                "Zoom returned a non-authoritative {$eventTypeLabel} result. Returned events were imported, but missing-event reconciliation was skipped and no local events were removed.",
            );
        }

        return $redirect;
    }

    public function fixActive(WebinarSeries $series): RedirectResponse
    {
        $webinar = $this->getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'No upcoming webinar events found.');
        }

        $this->flushWebinarCachesAction->handle(seriesSlug: $series->slug);

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Upcoming webinar event cache refreshed.');
    }

    public function updateSeriesProviderEventType(
        UpdateWebinarSeriesProviderEventTypeRequest $request,
        WebinarSeries $series,
    ): RedirectResponse {
        $eventType = (string) $request->validated('provider_event_type');

        $series->forceFill([
            'provider_event_type' => $eventType,
        ])->save();

        return redirect()
            ->route('crm.webinar-series.index')
            ->with(
                'success',
                'Series event type updated to '.$this->providerEventTypeLabel($eventType)
                .'. Existing occurrences were not changed.',
            );
    }

    public function replaceOccurrence(
        ReplaceWebinarOccurrenceRequest $request,
        Webinar $webinar,
        ReplaceWebinarOccurrenceAction $replaceWebinarOccurrence,
    ): RedirectResponse {
        $replacement = Webinar::query()->findOrFail(
            $request->integer('replacement_webinar_id'),
        );

        try {
            $result = $replaceWebinarOccurrence->handle(
                source: $webinar,
                replacement: $replacement,
            );
        } catch (LogicException $exception) {
            return redirect()
                ->route('crm.webinar-series.index', $this->indexQueryFor($webinar))
                ->with('error', $exception->getMessage());
        }

        $queueStatusCounts = collect($result['queue_statuses'])
            ->countBy()
            ->map(fn (int $count): int => $count)
            ->all();

        return redirect()
            ->route('crm.webinar-series.index', $this->indexQueryFor($webinar))
            ->with(
                'success',
                'Occurrence replacement prepared. Replacement registrations will finalize independently.',
            )
            ->with('occurrence_replacement_result', [
                ...$result,
                'source_title' => $webinar->title,
                'replacement_title' => $replacement->title,
                'queue_status_counts' => $queueStatusCounts,
            ]);
    }

    public function removeOccurrence(
        Webinar $webinar,
        RemoveWebinarOccurrenceAction $removeWebinarOccurrence,
    ): RedirectResponse {
        $result = $removeWebinarOccurrence->handle($webinar);

        if ($result['outcome'] === 'deleted') {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with(
                    'success',
                    'The event was permanently removed from Engage Core and will stay removed after future Zoom refreshes.',
                );
        }

        return redirect()
            ->route('crm.webinar-series.index', ['archived' => 1])
            ->with(
                'success',
                'The event was hidden from new registrations while its existing registrations and history were preserved.',
            );
    }

    public function updateSeriesScheduleProfile(
        UpdateWebinarSeriesScheduleProfileRequest $request,
        WebinarSeries $series,
    ): RedirectResponse {
        $series->forceFill([
            'webinar_schedule_profile_id' => $request->validated('webinar_schedule_profile_id'),
        ])->save();

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar message plan updated.');
    }

    public function updateWebinarScheduleProfile(
        UpdateWebinarSeriesScheduleProfileRequest $request,
        Webinar $webinar,
    ): RedirectResponse {
        $webinar->forceFill([
            'webinar_schedule_profile_id' => $request->validated(
                'webinar_schedule_profile_id',
            ),
        ])->save();

        return redirect()
            ->route('crm.webinar-series.index', [
                'messages' => $webinar->getKey(),
            ])
            ->with('success', 'Webinar message plan updated.');
    }

    public function destroySeries(
        WebinarSeries $series,
        RemoveWebinarSeriesAction $removeWebinarSeries,
    ): RedirectResponse {
        $result = $removeWebinarSeries->handle($series);

        if ($result === RemoveWebinarSeriesAction::RESULT_DELETED) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('success', 'Unused webinar type deleted.');
        }

        return redirect()
            ->route('crm.webinar-series.index', ['archived_types' => 1])
            ->with(
                'success',
                'Webinar type archived. Existing registrations, session history, and scheduled communication were preserved.',
            );
    }

    public function restoreSeries(
        WebinarSeries $series,
        RestoreWebinarSeriesAction $restoreWebinarSeries,
    ): RedirectResponse {
        try {
            $restored = $restoreWebinarSeries->handle($series);
        } catch (LogicException $exception) {
            return redirect()
                ->route('crm.webinar-series.show', $series)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.webinar-series.show', $restored)
            ->with('success', 'Webinar type restored and available for new registrations again.');
    }

    /**
     * @return array<string, string>
     */
    private function providerEventTypeOptions(): array
    {
        $provider = config('webinars.provider', 'zoom');
        $provider = is_string($provider) && trim($provider) !== ''
            ? strtolower(trim($provider))
            : 'zoom';
        $definitions = config("webinars.providers.{$provider}.event_types", []);

        if (! is_array($definitions)) {
            return [];
        }

        $options = [];

        foreach ($definitions as $eventType => $definition) {
            $resolved = WebinarProviderEventType::fromMixed($eventType);

            if (! $resolved instanceof WebinarProviderEventType || ! is_array($definition)) {
                continue;
            }

            $label = $definition['label'] ?? null;

            $options[$resolved->value] = is_string($label) && trim($label) !== ''
                ? trim($label)
                : Str::headline($resolved->value);
        }

        return $options;
    }

    private function providerEventTypeLabel(string $eventType): string
    {
        return $this->providerEventTypeOptions()[$eventType]
            ?? Str::headline($eventType);
    }

    /**
     * @param Collection<int, Webinar> $occurrences
     * @return array<int, Collection<int, Webinar>>
     */
    private function replacementCandidatesBySourceId(Collection $occurrences): array
    {
        $occurrencesBySeries = $occurrences->groupBy(
            fn (Webinar $webinar): int => (int) ($webinar->webinar_series_id ?? 0),
        );

        return $occurrences
            ->mapWithKeys(function (Webinar $source) use ($occurrencesBySeries): array {
                if ($source->webinar_series_id === null) {
                    return [(int) $source->getKey() => collect()];
                }

                $candidates = $occurrencesBySeries
                    ->get((int) $source->webinar_series_id, collect())
                    ->filter(function (Webinar $candidate) use ($source): bool {
                        if ($candidate->is($source)) {
                            return false;
                        }

                        if (! filled($candidate->external_id)) {
                            return false;
                        }

                        if (! $candidate->isProviderActive() || $candidate->isHidden()) {
                            return false;
                        }

                        if ($candidate->providerKey() !== $source->providerKey()) {
                            return false;
                        }

                        return $candidate->replacement_of_webinar_id === null
                            || (int) $candidate->replacement_of_webinar_id === (int) $source->getKey();
                    })
                    ->sortBy(fn (Webinar $candidate): string => implode('|', [
                        $candidate->starts_at?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59',
                        str_pad((string) $candidate->getKey(), 20, '0', STR_PAD_LEFT),
                    ]))
                    ->values();

                return [(int) $source->getKey() => $candidates];
            })
            ->all();
    }

    /** @return array<string, int> */
    private function indexQueryFor(Webinar $webinar): array
    {
        return $webinar->ends_at?->isPast()
            ? ['archived' => 1]
            : [];
    }

    private function devTestingAllowed(): bool
    {
        return app()->environment(['local', 'staging']);
    }
}