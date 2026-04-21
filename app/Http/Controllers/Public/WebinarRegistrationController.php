<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\CreateWebinarRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreWebinarRegistrationRequest;
use App\Jobs\Webinars\ProcessWebinarRegistration;
use App\Models\Webinar;
use App\Models\WebinarSeries;

class WebinarRegistrationController extends Controller
{
    public function index()
    {
        $nextWebinar = Webinar::query()
            ->with('series')
            ->where('status', 'active')
            ->whereNotNull('series_id')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        if ($nextWebinar) {
            return redirect()->route('webinar.show', $nextWebinar->series->slug);
        }

        $upcomingSeries = WebinarSeries::query()
            ->where('status', 'active')
            ->whereHas('webinars', function ($query) {
                $query->where('status', 'active')
                    ->where('ends_at', '>', now());
            })
            ->orderBy('title')
            ->get();

        return view('webinar.index', [
            'upcomingSeries' => $upcomingSeries,
        ]);
    }

    public function show(string $seriesSlug)
    {
        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->where('status', 'active')
            ->firstOrFail();

        $webinar = $this->resolveUpcomingWebinar($series);

        if (! $webinar) {
            $otherUpcomingSeries = WebinarSeries::query()
                ->where('status', 'active')
                ->where('id', '!=', $series->id)
                ->whereHas('webinars', function ($query) {
                    $query->where('status', 'active')
                        ->where('ends_at', '>', now());
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
        CreateWebinarRegistration $createWebinarRegistration
    ) {
        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->where('status', 'active')
            ->firstOrFail();

        $webinar = $this->resolveUpcomingWebinar($series);

        abort_unless($webinar, 404);

        $registration = $createWebinarRegistration->handle(
            $request->validated(),
            $request,
            $webinar->slug
        );

        ProcessWebinarRegistration::dispatch($registration->id);

        return redirect()->route('webinar.thank_you', $seriesSlug);
    }

    private function resolveUpcomingWebinar(WebinarSeries $series): ?Webinar
    {
        return $series->webinars()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }
}