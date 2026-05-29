<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\CreateWebinarRegistrationAction;
use App\Actions\Webinars\GetActiveWebinarSeriesAction;
use App\Actions\Webinars\GetNextUpcomingWebinarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreWebinarRegistrationRequest;
use App\Support\Caching\CacheKey;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class WebinarRegistrationController extends Controller
{
    public function index(GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction)
    {
        return view('webinar.index', [
            'series' => $getActiveWebinarSeriesAction->handle(),
        ]);
    }

    public function show(
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ): Response {
        if (! config('cache-keys.enabled')) {
            return response($this->renderShowPage(
                $seriesSlug,
                $getActiveWebinarSeriesAction,
                $getNextUpcomingWebinarAction
            ));
        }

        $html = Cache::remember(
            CacheKey::webinarLandingPage($seriesSlug),
            (int) config('cache-keys.ttl.webinar_landing_page_seconds'),
            fn (): string => $this->renderShowPage(
                $seriesSlug,
                $getActiveWebinarSeriesAction,
                $getNextUpcomingWebinarAction
            )
        );

        return response($html);
    }

    private function renderShowPage(
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ): string {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            return view('webinar.notify-me', [
                'series' => $series,
            ])->render();
        }

        return view('webinar.register', [
            'webinar' => $webinar,
            'series' => $series,
        ])->render();
    }

    public function store(
        StoreWebinarRegistrationRequest $request,
        string $seriesSlug,
        CreateWebinarRegistrationAction $createWebinarRegistrationAction,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ) {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            return redirect()->route('webinar.show', [
                'seriesSlug' => $series->slug,
            ]);
        }

        $createWebinarRegistrationAction->handle(
            $request->validated(),
            $request,
            $webinar->slug
        );

        return redirect()->route('webinar.thank-you', $seriesSlug);
    }

    public function showThankYou(
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ) {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        return view('webinar.thank-you', [
            'series' => $series,
            'webinar' => $webinar,
        ]);
    }
}