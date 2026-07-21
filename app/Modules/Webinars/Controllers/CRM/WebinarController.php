<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\ReplaceWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Requests\ReplaceWebinarOccurrenceRequest;
use App\Modules\Webinars\Requests\StoreWebinarSeriesRequest;
use App\Modules\Webinars\Requests\SyncWebinarSeriesRequest;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesProviderEventTypeRequest;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesScheduleProfileRequest;
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

    public function index(Request $request): View
    {
        $series = WebinarSeries::query()
            ->with('webinarScheduleProfile')
            ->orderBy('title')
            ->get();

        $scheduleProfiles = WebinarScheduleProfile::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
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
            $query->whereHas('registrations', fn ($query) => $query
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
        } elseif (! $showArchived) {
            $query->where('ends_at', '>', now());
        }

        $webinars = $query
            ->when(
                $showAttention,
                fn ($query) => $query->orderByDesc('starts_at'),
                fn ($query) => $query->orderBy('starts_at'),
            )
            ->limit(50)
            ->get();

        return view('crm.webinars.index', [
            'title' => 'Webinars',
            'heading' => 'Webinars',
            'webinars' => $webinars,
            'series' => $series,
            'scheduleProfiles' => $scheduleProfiles,
            'showArchived' => $showArchived,
            'showAttention' => $showAttention,
            'providerEventTypeOptions' => $this->providerEventTypeOptions(),
            'replacementCandidatesBySourceId' => $this->replacementCandidatesBySourceId(
                $allOccurrences,
            ),
            'webinarDevEnabled' => $this->devTestingAllowed(),
            'webinarSmokeEnabled' => $this->devTestingAllowed(),
        ]);
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

        $redirect = redirect()
            ->route('crm.webinar-series.index')
            ->with(
                'success',
                "Sync complete: {$result['created']} created, {$result['updated']} updated, {$result['deleted']} deleted, "
                .count($result['missing']).' missing preserved.'
            )
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

    public function updateSeriesScheduleProfile(
        UpdateWebinarSeriesScheduleProfileRequest $request,
        WebinarSeries $series,
    ): RedirectResponse {
        $series->forceFill([
            'webinar_schedule_profile_id' => $request->validated('webinar_schedule_profile_id'),
        ])->save();

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar schedule profile updated.');
    }

    public function destroySeries(WebinarSeries $series): RedirectResponse
    {
        if (Webinar::query()->where('webinar_series_id', $series->id)->exists()) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'Cannot delete a series that has webinar events.');
        }

        $seriesSlug = $series->slug;

        $series->delete();

        $this->flushWebinarCachesAction->handle(seriesSlug: $seriesSlug);

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar series deleted.');
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