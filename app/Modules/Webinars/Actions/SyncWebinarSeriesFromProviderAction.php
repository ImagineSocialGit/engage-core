<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Carbon\Carbon;
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

        $webinarProvider = $this->webinarProviderManager->provider();
        $provider = $webinarProvider->key();

        $fetchedWebinars = collect($webinarProvider->listWebinarsByTitle($series->title))
            ->values();

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $missing = [];

        $fetchedExternalIds = $fetchedWebinars
            ->map(fn (ProviderWebinarData $webinar) => $webinar->externalId)
            ->filter()
            ->values()
            ->all();

        $fetchedWebinars->each(function (ProviderWebinarData $fetchedWebinar) use ($series, $provider, &$created, &$updated): void {
            $webinar = Webinar::query()->firstOrNew([
                'platform' => $provider,
                'external_id' => $fetchedWebinar->externalId,
                'webinar_series_id' => $series->id,
            ]);

            $webinar->fill([
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
                'meta' => $fetchedWebinar->meta,
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

        $missingWebinars = $this->missingWebinars(
            series: $series,
            provider: $provider,
            fetchedExternalIds: $fetchedExternalIds,
        );

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
            'deleted' => $deleted,
            'conflicts' => [],
            'missing' => $missing,
        ];
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
        array $fetchedExternalIds,
    ): Collection {
        return $series->webinars()
            ->where('platform', $provider)
            ->when(
                filled($fetchedExternalIds),
                fn ($query) => $query->whereNotIn('external_id', $fetchedExternalIds),
            )
            ->get();
    }

    protected function makeSlug(string $title, ?Carbon $startTime, string $externalId): string
    {
        if ($startTime) {
            return Str::slug($title.'-'.$startTime->format('Y-m-d-gia'));
        }

        return Str::slug($title.'-'.$externalId);
    }
}
