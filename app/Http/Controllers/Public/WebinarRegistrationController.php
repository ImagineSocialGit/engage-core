<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\CreateWebinarRegistration;
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
        $html = Cache::remember(
            CacheKey::webinarLandingPage($seriesSlug),
            (int) config('cache-keys.ttl.webinar_landing_page_seconds'),
            function () use ($seriesSlug, $getActiveWebinarSeriesAction, $getNextUpcomingWebinarAction): string {
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
        );

        return response($html);
    }

    public function store(
        StoreWebinarRegistrationRequest $request,
        string $seriesSlug,
        CreateWebinarRegistration $createWebinarRegistration,
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

        $createWebinarRegistration->handle(
            $request->validated(),
            $request,
            $webinar->slug
        );

        return redirect()->route('webinar.thank-you', $seriesSlug);
    }

    public function showThankYou(
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction
    ) {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        return view('webinar.thank-you', [
            'series' => $series,
        ]);
    }
}