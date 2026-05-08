<?php

namespace App\Http\Controllers\CRM;

use App\Actions\Webinars\AdvanceWebinarSeriesStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreWebinarSeriesRequest;
use App\Http\Requests\CRM\SyncWebinarSeriesRequest;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Services\Zoom\ZoomWebinarService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebinarController extends Controller
{

    public function index(Request $request,
        AdvanceWebinarSeriesStatusAction $advanceWebinarSeriesStatusAction
    ): View{
        $series = WebinarSeries::query()
            ->orderBy('title')
            ->get();

        foreach ($series as $seriesItem) {
            $advanceWebinarSeriesStatusAction->execute($seriesItem);
        }

        $showArchived = $request->boolean('archived');

        $query = Webinar::query()
            ->with('series');

        if (! $showArchived) {
            $query->where('status', '!=', 'completed');
        }

        $webinars = $query
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return view('crm.webinar-series.index', [
            'title' => 'Webinars',
            'heading' => 'Webinars',
            'webinars' => $webinars,
            'series' => $series,
            'showArchived' => $showArchived,
        ]);
    }

    public function storeSeries(StoreWebinarSeriesRequest $request): RedirectResponse
    {
        WebinarSeries::query()->create($request->validated());

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar series created.');
    }

    public function syncSeries(
        SyncWebinarSeriesRequest $request,
        ZoomWebinarService $zoomWebinarService
    ): RedirectResponse {
        $series = WebinarSeries::query()->findOrFail($request->validated('series_id'));

        $fetchedWebinars = $zoomWebinarService->listWebinarsByTitle($series->title);

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $conflicts = [];

        $fetchedExternalIds = collect($fetchedWebinars)
            ->pluck('external_id')
            ->all();

        foreach ($fetchedWebinars as $fetchedWebinar) {
            $webinar = Webinar::query()->firstOrNew([
                'platform' => 'zoom',
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
                $webinar->status = 'scheduled';
                $webinar->provider_settings = null;
            }

            $webinar->save();

            if ($webinar->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $seriesWebinars = $series->webinars()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        $active = $seriesWebinars->firstWhere('status', 'active');
        $earliest = $seriesWebinars->first();

        if ($active && $earliest && $active->id !== $earliest->id) {
            $conflicts[] = [
                'series_id' => $series->id,
                'series' => $series->title,
                'active' => $active->title,
                'expected' => $earliest->title,
            ];
        }

        $missingWebinars = $series->webinars()
            ->where('platform', 'zoom')
            ->whereNotIn('external_id', $fetchedExternalIds)
            ->get();

        $missing = [];

        foreach ($missingWebinars as $missingWebinar) {
            if ($missingWebinar->status === 'completed') {
                continue;
            }

            $hasRegistrations = $missingWebinar->registrations()->exists();

            if (
                $missingWebinar->status === 'scheduled'
                && ! $hasRegistrations
            ) {
                $missingWebinar->delete();

                $deleted++;

                continue;
            }

            $missing[] = [
                'title' => $missingWebinar->title,
                'status' => $missingWebinar->status,
                'has_registrations' => $hasRegistrations,
            ];
        }

        return redirect()
            ->route('crm.webinar-series.index')
            ->with(
                'success',
                "Sync complete: {$created} created, {$updated} updated, {$deleted} deleted, "
                .count($missing)." missing preserved."
            )
            ->with('sync_conflicts', $conflicts)
            ->with('sync_missing', $missing);
    }

    public function fixActive(WebinarSeries $series): RedirectResponse
    {
        $webinars = $series->webinars()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        $earliest = $webinars->first();

        if (! $earliest) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'No upcoming webinars found.');
        }

        $currentActive = $webinars->firstWhere('status', 'active');

        if (! $currentActive) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'No active webinar to correct.');
        }

        if ($currentActive->id === $earliest->id) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'Active webinar is already correct.');
        }

        // Set all to scheduled except completed
        $series->webinars()
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'scheduled',
            ]);

        $earliest->update([
            'status' => 'active',
        ]);

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Active webinar corrected.');
    }

    protected function makeSlug(string $title, ?Carbon $startTime, string $externalId): string
    {
        if ($startTime) {
            return Str::slug($title.'-'.$startTime->format('Y-m-d-gia'));
        }

        return Str::slug($title.'-'.$externalId);
    }
}