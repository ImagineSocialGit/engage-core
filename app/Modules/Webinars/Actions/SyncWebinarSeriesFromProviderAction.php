<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SyncWebinarSeriesFromProviderAction
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCachesAction,
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
        private readonly WebinarProviderManager $webinarProviderManager,
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
        $fetchedWebinars = collect($snapshot->webinars)->values();

        $created = 0;
        $updated = 0;
        $missing = [];

        $fetchedExternalIds = $fetchedWebinars
            ->map(fn (ProviderWebinarData $webinar) => $webinar->externalId)
            ->filter()
            ->values()
            ->all();

        $fetchedWebinars->each(function (ProviderWebinarData $fetchedWebinar) use ($series, $provider, $providerEventType, &$created, &$updated): void {
            $webinar = Webinar::query()->firstOrNew([
                'platform' => $provider,
                'provider_event_type' => $providerEventType,
                'external_id' => $fetchedWebinar->externalId,
                'webinar_series_id' => $series->id,
            ]);

            $webinar->fill([
                'platform' => $provider,
                'provider_event_type' => $providerEventType,
                'title' => $fetchedWebinar->title,
                'slug' => $this->makeSlug(
                    title: $fetchedWebinar->title,
                    startTime: $fetchedWebinar->startsAt,
                    externalId: $fetchedWebinar->externalId,
                ),
                'join_url' => $fetchedWebinar->joinUrl,
                'registration_url' => $fetchedWebinar->registrationUrl ?? $webinar->registration_url,
                'starts_at' => $fetchedWebinar->startsAt,
                'ends_at' => $fetchedWebinar->endsAt,
                'timezone' => $fetchedWebinar->timezone,
                'description' => $fetchedWebinar->description,
                'meta' => $this->mergeProviderMeta(
                    webinar: $webinar,
                    provider: $provider,
                    providerMeta: $fetchedWebinar->meta,
                ),
            ]);

            if (! $webinar->exists) {
                $webinar->provider_settings = null;
            }

            $webinar->save();

            if ($webinar->wasRecentlyCreated) {
                $created++;

                return;
            }

            $updated++;
        });

        if ($snapshot->authoritative) {
            foreach ($this->missingWebinars(
                series: $series,
                provider: $provider,
                providerEventType: $providerEventType,
                fetchedExternalIds: $fetchedExternalIds,
            ) as $missingWebinar) {
                $missing[] = [
                    'webinar_id' => $missingWebinar->getKey(),
                    'external_id' => $missingWebinar->external_id,
                    'platform' => $missingWebinar->providerKey(),
                    'provider_event_type' => $missingWebinar->providerEventTypeKey(),
                    'title' => $missingWebinar->title,
                    'has_registrations' => $missingWebinar->registrations()->exists(),
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

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => 0,
            'conflicts' => [],
            'missing' => $missing,
            'reconciliation' => [
                'authoritative' => $snapshot->authoritative,
                'reason' => $snapshot->reason,
                'provider' => $provider,
                'provider_event_type' => $providerEventType,
                'missing_candidates' => count($missing),
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
            ->whereNull('notified_at')
            ->exists();
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
            ->when(
                filled($fetchedExternalIds),
                fn ($query) => $query->whereNotIn('external_id', $fetchedExternalIds),
            )
            ->get();
    }

    protected function makeSlug(string $title, ?CarbonInterface $startTime, string $externalId): string
    {
        if ($startTime) {
            return Str::slug($title.'-'.$startTime->format('Y-m-d-gia'));
        }

        return Str::slug($title.'-'.$externalId);
    }
}