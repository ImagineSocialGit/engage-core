<?php

namespace App\Actions\Webinars;

use App\Actions\Caching\FlushWebinarCachesAction;
use App\Jobs\Webinars\NotifyWebinarWaitlistJob;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Services\Zoom\ZoomWebinarService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SyncWebinarSeriesFromProviderAction
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCachesAction,
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
        private readonly ZoomWebinarService $zoomWebinarService,
    ) {}

    public function execute(WebinarSeries $series): array
    {
        $hadUpcomingWebinarBeforeSync = filled(
            $this->getNextUpcomingWebinarAction->getForSeries($series)
        );

        $fetchedWebinars = $this->zoomWebinarService->listWebinarsByTitle($series->title);

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $missing = [];

        $provider = config('webinars.provider');

        $fetchedExternalIds = collect($fetchedWebinars)
            ->pluck('external_id')
            ->all();

        foreach ($fetchedWebinars as $fetchedWebinar) {
            $webinar = Webinar::query()->firstOrNew([
                'platform' => $provider,
                'external_id' => $fetchedWebinar['external_id'],
                'series_id' => $series->id,
            ]);

            $webinar->fill([
                'title' => $fetchedWebinar['title'],
                'slug' => $this->makeSlug(
                    title: $fetchedWebinar['title'],
                    startTime: $fetchedWebinar['starts_at'],
                    externalId: $fetchedWebinar['external_id'],
                ),
                'join_url' => $fetchedWebinar['join_url'],
                'registration_url' => $fetchedWebinar['registration_url'],
                'starts_at' => $fetchedWebinar['starts_at'],
                'ends_at' => $fetchedWebinar['ends_at'],
                'timezone' => $fetchedWebinar['timezone'],
                'description' => $fetchedWebinar['description'],
                'meta' => $fetchedWebinar['meta'],
            ]);

            if (! $webinar->exists) {
                $webinar->provider_settings = null;
            }

            $webinar->save();

            if ($webinar->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $missingWebinars = $series->webinars()
            ->where('platform', $provider)
            ->whereNotIn('external_id', $fetchedExternalIds)
            ->get();

        foreach ($missingWebinars as $missingWebinar) {
            $hasRegistrations = $missingWebinar->registrations()->exists();

            if (! $hasRegistrations) {
                $missingWebinar->delete();
                $deleted++;

                continue;
            }

            $missing[] = [
                'title' => $missingWebinar->title,
                'has_registrations' => $hasRegistrations,
            ];
        }

        $this->getNextUpcomingWebinarAction->forgetForSeries($series);
        $this->getNextUpcomingWebinarAction->forgetGlobal();

        $this->flushWebinarCachesAction->handle(seriesSlug: $series->slug);

        $hasUpcomingWebinarAfterSync = filled(
            $this->getNextUpcomingWebinarAction->getForSeries($series)
        );

        if (! $hadUpcomingWebinarBeforeSync && $hasUpcomingWebinarAfterSync) {
            NotifyWebinarWaitlistJob::dispatch($series->id);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'conflicts' => [],
            'missing' => $missing,
        ];
    }

    protected function makeSlug(string $title, ?Carbon $startTime, string $externalId): string
    {
        if ($startTime) {
            return Str::slug($title.'-'.$startTime->format('Y-m-d-gia'));
        }

        return Str::slug($title.'-'.$externalId);
    }
}