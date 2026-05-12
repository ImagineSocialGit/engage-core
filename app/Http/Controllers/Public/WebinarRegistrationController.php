<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\AdvanceWebinarSeriesStatusAction;
use App\Actions\Webinars\CreateWebinarRegistration;
use App\Actions\Webinars\GetNextUpcomingWebinarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreWebinarRegistrationRequest;
use App\Models\WebinarSeries;

class WebinarRegistrationController extends Controller
{
    public function index(GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction)
    {
        $nextWebinar = $getNextUpcomingWebinarAction->getGlobal();

        if ($nextWebinar) {
            return redirect()->route('webinar.show', $nextWebinar->series->slug);
        }

        $upcomingSeries = WebinarSeries::query()
            ->where('status', 'active')
            ->whereHas('webinars', function ($query) {
                $query->where('status', 'active')
                    ->where('starts_at', '>', now()->subMinutes(10));
            })
            ->orderBy('title')
            ->get();

        return view('webinar.index', [
            'upcomingSeries' => $upcomingSeries,
        ]);
    }

    public function show(
        string $seriesSlug,
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

            return view('webinar.none-scheduled', [
                'series' => $series,
                'otherUpcomingSeries' => $otherUpcomingSeries,
            ]);
        }

        return view('webinar.register', compact('webinar', 'series'));
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