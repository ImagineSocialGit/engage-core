<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\AdvanceWebinarSeriesStatusAction;
use App\Actions\Webinars\CreateWebinarRegistration;
use App\Actions\Webinars\GetActiveWebinarSeriesAction;
use App\Actions\Webinars\GetNextUpcomingWebinarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreWebinarRegistrationRequest;
use App\Models\WebinarSeries;
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
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ): Response {
        $html = Cache::remember(
            CacheKey::webinarLandingPage($seriesSlug),
            (int) config('cache-keys.ttl.webinar_landing_page_seconds'),
            function () use ($seriesSlug, $getNextUpcomingWebinarAction): string {
                $series = WebinarSeries::query()
                    ->where('slug', $seriesSlug)
                    ->firstOrFail();

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
        AdvanceWebinarSeriesStatusAction $advanceWebinarSeriesStatusAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ) {
        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->firstOrFail();

        $advanceWebinarSeriesStatusAction->execute($series);
        $series->refresh();

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        if (! $webinar) {
            $otherUpcomingSeries = WebinarSeries::query()
                ->where('status', 'active')
                ->where('id', '!=', $series->id)
                ->whereHas('webinars', function ($query) {
                    $query->where('status', 'active')
                        ->where('starts_at', '>', now()->subMinutes(10));
                })
                ->orderBy('title')
                ->get();

            return response()->view('webinar.none-scheduled', [
                'series' => $series,
                'otherUpcomingSeries' => $otherUpcomingSeries,
            ], 409);
        }

        $registration = $createWebinarRegistration->handle(
            $request->validated(),
            $request,
            $webinar->slug
        );

        return redirect()->route('webinar.thank-you', $seriesSlug);
    }

    public function showThankYou(string $seriesSlug)
    {
        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->firstOrFail();

        return view('webinar.thank-you', [
            'series' => $series,
        ]);
    }
}