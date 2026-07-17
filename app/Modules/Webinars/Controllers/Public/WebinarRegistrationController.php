<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Requests\StoreWebinarRegistrationRequest;
use Illuminate\Http\Response;

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
        return response($this->renderShowPage(
            $seriesSlug,
            $getActiveWebinarSeriesAction,
            $getNextUpcomingWebinarAction,
        ));
    }

    public function showFromWaitlist(
        string $seriesSlug,
        int $signup,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
    ) {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        abort_unless($webinar, 404);

        $waitlistSignup = WebinarWaitlistSignup::query()
            ->with('contact')
            ->whereKey($signup)
            ->where('webinar_series_id', $series->getKey())
            ->firstOrFail();

        $contact = $waitlistSignup->contact;

        session()->flashInput([
            'first_name' => $contact?->first_name,
            'last_name' => $contact?->last_name,
            'email' => $contact?->email,
            'phone' => $contact?->phone,
        ]);

        return response($this->renderShowPage(
            $seriesSlug,
            $getActiveWebinarSeriesAction,
            $getNextUpcomingWebinarAction,
            [
                'first_name' => $contact?->first_name,
                'last_name' => $contact?->last_name,
                'email' => $contact?->email,
                'phone' => $contact?->phone,
            ],
        ));
    }

    private function renderShowPage(
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
        array $registrationPrefill = [],
    ): string {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $getNextUpcomingWebinarAction->getForSeries($series);

        $config = app(\App\Modules\Webinars\Support\WebinarRegisterPageConfig::class);
        $channelAvailability = app(MessageChannelAvailability::class);

        if (! $webinar) {
            return view('webinar.notify-me', [
                'series' => $series,
                'page' => $config->content('notify-me', $series->slug, $series->meta ?? []),
                'style' => $config->style('notify-me', $series->slug),
                'webinarWaitlistChannels' => [
                    'marketing' => $channelAvailability->visibleChannelsForSurface(
                        surface: 'webinar_waitlists',
                        purpose: 'marketing',
                        scope: 'webinar_waitlist',
                    ),
                ],
            ])->render();
        }

        return view('webinar.register', [
            'webinar' => $webinar,
            'series' => $series,
            'page' => $config->content('register', $series->slug, $series->meta ?? []),
            'style' => $config->style('register', $series->slug),
            'registrationPrefill' => $registrationPrefill,
            'webinarRegistrationChannels' => [
                'transactional' => $channelAvailability->visibleChannelsForSurface(
                    surface: 'webinar_registrations',
                    purpose: 'transactional',
                    scope: 'webinar',
                ),
                'marketing' => $channelAvailability->visibleChannelsForSurface(
                    surface: 'webinar_registrations',
                    purpose: 'marketing',
                    scope: 'webinar_nurture',
                ),
            ],
        ])->render();
    }

    public function store(
        StoreWebinarRegistrationRequest $request,
        string $seriesSlug,
        CreateWebinarRegistrationAction $createWebinarRegistrationAction,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
    ) {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $webinar = $request->registerableWebinar();

        if (! $webinar) {
            return redirect()->route('webinar.show', [
                'seriesSlug' => $series->slug,
            ]);
        }

        $createWebinarRegistrationAction->handle(
            $request->validated(),
            $request,
            $webinar,
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
