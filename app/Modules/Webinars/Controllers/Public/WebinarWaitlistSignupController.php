<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\CreateWebinarWaitlistSignupAction;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Requests\StoreWebinarWaitlistSignupRequest;
use Illuminate\Http\RedirectResponse;

class WebinarWaitlistSignupController extends Controller
{
    public function __invoke(
        StoreWebinarWaitlistSignupRequest $request,
        string $seriesSlug,
        GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction,
        CreateWebinarWaitlistSignupAction $createWebinarWaitlistSignupAction,
    ): RedirectResponse {
        $series = $getActiveWebinarSeriesAction->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $createWebinarWaitlistSignupAction->handle(
            validated: $request->validated(),
            request: $request,
            series: $series,
            acceptedChannels: $request->acceptedMarketingChannels(),
        );

        return redirect()
            ->route('webinar.show', $series->slug)
            ->with('webinar_waitlist_success', true)
            ->with(
                'success',
                config(
                    'webinars.notify-me.content.success.message',
                    'You’re on the list. We’ll let you know when the next webinar is scheduled.'
                )
            );
    }
}
