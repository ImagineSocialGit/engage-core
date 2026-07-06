<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Requests\StoreWebinarSeriesRequest;
use App\Modules\Webinars\Requests\SyncWebinarSeriesRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebinarController extends Controller
{
    public function __construct(
        private readonly FlushWebinarCachesAction $flushWebinarCachesAction,
        private readonly GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
    ) {}

    public function index(Request $request): View
    {
        $series = WebinarSeries::query()
            ->orderBy('title')
            ->get();

        $showArchived = $request->boolean('archived');

        $query = Webinar::query()
            ->with([
                'webinarSeries',
                'registrations' => fn ($query) => $query
                    ->with('contact')
                    ->latest('registered_at')
                    ->latest('id'),
            ]);

        if (! $showArchived) {
            $query->where('ends_at', '>', now());
        }

        $webinars = $query
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return view('crm.webinars.index', [
            'title' => 'Webinars',
            'heading' => 'Webinars',
            'webinars' => $webinars,
            'series' => $series,
            'showArchived' => $showArchived,
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

        return redirect()
            ->route('crm.webinar-series.index')
            ->with(
                'success',
                "Sync complete: {$result['created']} created, {$result['updated']} updated, {$result['deleted']} deleted, "
                .count($result['missing']).' missing preserved.'
            )
            ->with('sync_conflicts', $result['conflicts'])
            ->with('sync_missing', $result['missing']);
    }

    public function fixActive(WebinarSeries $series): RedirectResponse
    {
        $webinar = $this->getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'No upcoming webinars found.');
        }

        $this->flushWebinarCachesAction->handle(seriesSlug: $series->slug);

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Upcoming webinar cache refreshed.');
    }

    public function destroySeries(WebinarSeries $series): RedirectResponse
    {
        if (Webinar::query()->where('webinar_series_id', $series->id)->exists()) {
            return redirect()
                ->route('crm.webinar-series.index')
                ->with('error', 'Cannot delete a series that has webinars.');
        }

        $seriesSlug = $series->slug;

        $series->delete();

        $this->flushWebinarCachesAction->handle(seriesSlug: $seriesSlug);

        return redirect()
            ->route('crm.webinar-series.index')
            ->with('success', 'Webinar series deleted.');
    }

    private function devTestingAllowed(): bool
    {
        return app()->environment(['local', 'staging']);
    }
}
